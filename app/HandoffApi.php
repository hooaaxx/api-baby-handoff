<?php

namespace App;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

final class HandoffApi
{
    public function register(Request $request)
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name'=>'required|string|max:60', 'email'=>'required|email|max:255|unique:users',
            'password'=>'required|string|min:8|max:72',
        ]);
        $data['password'] = Hash::make($data['password']);
        return response()->json($this->issueToken(User::create($data)), 201);
    }

    public function login(Request $request): array
    {
        $data = $request->validate(['email'=>'required|email|max:255','password'=>'required|string|max:72']);
        $user = User::where('email', Str::lower(trim($data['email'])))->first();
        abort_unless($user && Hash::check($data['password'], $user->password), 401, 'Email or password is incorrect.');
        return $this->issueToken($user);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->noContent();
    }

    private function issueToken(User $user): array
    {
        return ['token'=>$user->createToken('baby-handoff-mobile')->plainTextToken,
            'user'=>['id'=>$user->id,'name'=>$user->name]];
    }

    public function families(Request $request): array
    {
        return DB::table('families')->join('family_members', 'families.id', '=', 'family_members.family_id')
            ->where('family_members.user_id', $request->user()->id)->orderBy('families.created_at')
            ->get(['families.*'])->map(fn ($row) => $this->familyState($row, $request->user()->id))->all();
    }

    public function createFamily(Request $request)
    {
        $data = $request->validate(['baby_name'=>'required|string|max:60']);
        return DB::transaction(function () use ($request, $data) {
            $user = $request->user()->id;
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            $existing = DB::table('families')->where('owner_id', $user)->first();
            if ($existing) {
                return response()->json($this->familyState($existing, $user));
            }
            $id = (string) Str::uuid();
            DB::table('families')->insert([
                'id'=>$id, 'owner_id'=>$user, 'baby_name'=>$data['baby_name'],
                'on_duty_id'=>$user, 'on_duty_since'=>now(), 'created_at'=>now(), 'updated_at'=>now(),
            ]);
            DB::table('family_members')->insert(['family_id'=>$id,'user_id'=>$user]);
            return response()->json($this->familyState($this->family($id), $user), 201);
        }, 3);
    }

    public function status(Request $request, string $family): array
    {
        $row = $this->memberFamily($family, $request->user()->id);
        return $this->familyState($row, $request->user()->id);
    }

    public function testActivate(Request $request, string $family): array
    {
        abort_unless($this->testEnabled(), 404);
        return DB::transaction(function () use ($request, $family) {
            $row = $this->memberFamily($family, $request->user()->id, true);
            abort_unless((int)$row->owner_id === $request->user()->id, 403, 'Only the family owner can activate sync.');
            DB::table('families')->where('id', $family)->update(['test_sync'=>true]);
            $row->test_sync = true;
            return $this->familyState($row, $request->user()->id);
        }, 3);
    }

    public function invite(Request $request, string $family): array
    {
        return DB::transaction(function () use ($request, $family) {
            $row = $this->memberFamily($family, $request->user()->id, true);
            $this->requireSync($row);
            abort_unless((int)$row->owner_id === $request->user()->id, 403, 'Only the family owner can invite a caregiver.');
            abort_if(DB::table('family_members')->where('family_id', $family)->count() >= 2, 409, 'Both caregiver spots are filled.');
            DB::table('family_invites')->where('family_id', $family)->whereNull('redeemed_at')->delete();
            $code = Str::upper(Str::random(10));
            $expires = now()->addDay();
            DB::table('family_invites')->insert(['code_hash'=>hash('sha256', $code),'family_id'=>$family,'expires_at'=>$expires]);
            return ['code'=>$code,'expires_at'=>$expires->toIso8601String()];
        }, 3);
    }

    public function redeem(Request $request): array
    {
        $data = $request->validate(['code'=>'required|string|max:30']);
        $code = hash('sha256', Str::upper(preg_replace('/[\s-]+/', '', $data['code'])));
        $id = DB::table('family_invites')->where('code_hash', $code)->value('family_id');
        abort_unless($id, 422, 'This invite code is invalid or has expired.');
        return DB::transaction(function () use ($request, $id, $code) {
            $family = $this->family($id, true);
            $this->requireSync($family);
            $user = $request->user()->id;
            if ($this->isMember($id, $user)) {
                return $this->familyState($family, $user);
            }
            $invite = DB::table('family_invites')->where('code_hash', $code)->first();
            abort_if(!$invite || $invite->redeemed_at || Carbon::parse($invite->expires_at)->isPast(), 422,
                'This invite code is invalid, used, or expired.');
            abort_if(DB::table('family_members')->where('family_id', $id)->count() >= 2, 409, 'Both caregiver spots are filled.');
            DB::table('family_members')->insert(['family_id'=>$id,'user_id'=>$user]);
            DB::table('family_invites')->where('code_hash', $code)->update(['redeemed_at'=>now()]);
            return $this->familyState($family, $user);
        }, 3);
    }

    public function sync(Request $request, string $family): array
    {
        $data = $request->validate([
            'cursor'=>'required|integer|min:0', 'changes'=>'present|array|max:100',
            'changes.*.op_id'=>'required|uuid|distinct', 'changes.*.id'=>'required|uuid',
            'changes.*.base_revision'=>'required|integer|min:0',
            'changes.*.operation'=>['required',Rule::in(['upsert','delete'])],
            'changes.*.event'=>'nullable|array',
        ]);
        return DB::transaction(function () use ($request, $family, $data) {
            $actor = $request->user()->id;
            $row = $this->memberFamily($family, $actor, true);
            $this->requireSync($row);
            abort_if($data['cursor'] > $row->revision, 422, 'Sync cursor is invalid for this family.');
            $results = [];
            foreach ($data['changes'] as $change) {
                $results[] = $this->applyChange($row, $actor, $change);
            }
            DB::table('families')->where('id', $family)->update(['revision'=>$row->revision]);
            $page = $this->events($family)->where('revision','>',$data['cursor'])->orderBy('revision')->limit(201)->get();
            $events = $page->take(200)->map(fn ($event) => $this->event($event));
            return [
                'results'=>$results,'events'=>$events->values(),
                'cursor'=>$page->count() > 200 ? (int)$events->last()['revision'] : (int)$row->revision,
                'has_more'=>$page->count() > 200,
            ];
        }, 3);
    }

    private function applyChange(stdClass $family, int $actor, array $change): array
    {
        $operationKey = ['family_id'=>$family->id,'user_id'=>$actor,'id'=>$change['op_id']];
        $previous = DB::table('sync_operations')->where($operationKey)->value('result');
        if ($previous !== null) {
            return json_decode($previous, true);
        }
        $key = ['family_id'=>$family->id,'id'=>$change['id']];
        $event = $this->events($family->id)->where('care_events.id', $change['id'])->first();
        $result = ['op_id'=>$change['op_id'],'id'=>$change['id']];
        if ((int)($event->revision ?? 0) !== (int)$change['base_revision'] ||
            (!$event && $change['operation'] === 'delete')) {
            $result += ['status'=>'conflict','current'=>$event ? $this->event($event) : null];
        } else {
            try {
                $value = $change['operation'] === 'upsert' ? $this->validatedEvent($change['event'] ?? []) : ['deleted'=>true];
                if (($value['type'] ?? '') === 'sleep' && $value['ended_at'] === null &&
                    DB::table('care_events')->where('family_id', $family->id)->where('type','sleep')
                        ->where('deleted',false)->whereNull('ended_at')->where('id','!=',$change['id'])->exists()) {
                    throw ValidationException::withMessages(['sleep'=>'Another sleep session is already active.']);
                }
                $value['revision'] = ++$family->revision;
                $value['author_id'] = $event->author_id ?? $actor;
                DB::table('care_events')->updateOrInsert($key, $value);
                $result += ['status'=>'applied','revision'=>(int)$value['revision']];
            } catch (ValidationException $error) {
                $result += ['status'=>'invalid','message'=>collect($error->errors())->flatten()->first()];
            }
        }
        DB::table('sync_operations')->insert($operationKey + ['result'=>json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    }

    private function validatedEvent(array $input): array
    {
        $data = validator($input, [
            'type'=>'required|in:feed,diaper,sleep','started_at'=>'required|date',
            'ended_at'=>'nullable|date|after_or_equal:started_at',
            'kind'=>'nullable|in:bottle,breast,wet,dirty,both',
            'amount'=>'nullable|integer|between:1,9999','note'=>'nullable|string|max:500',
        ])->validate();
        $type = $data['type'];
        $kind = $data['kind'] ?? null;
        if (($type === 'feed' && !in_array($kind, ['bottle','breast'], true)) ||
            ($type === 'diaper' && !in_array($kind, ['wet','dirty','both'], true))) {
            throw ValidationException::withMessages(['kind'=>'Select a valid feed or diaper type.']);
        }
        return [
            'type'=>$type, 'started_at'=>Carbon::parse($data['started_at'])->utc()->toDateTimeString(),
            'ended_at'=>$type === 'sleep' && !empty($data['ended_at']) ? Carbon::parse($data['ended_at'])->utc()->toDateTimeString() : null,
            'details'=>json_encode(['kind'=>$type === 'sleep' ? null : $kind,'amount'=>$type === 'feed' ? ($data['amount'] ?? null) : null,
                'note'=>$data['note'] ?? null], JSON_THROW_ON_ERROR),
            'deleted'=>false,
        ];
    }

    public function currentHandoff(Request $request, string $family): array
    {
        $row = $this->memberFamily($family, $request->user()->id);
        $this->requireSync($row);
        $current = DB::table('handoffs')->where('family_id', $family)->orderByRaw('CASE WHEN accepted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('accepted_at')->orderByDesc('created_at')->first();
        return ['handoff'=>$current ? $this->handoff($current) : null,'family'=>$this->familyState($row,$request->user()->id)];
    }

    public function sendHandoff(Request $request, string $family): array
    {
        $data = $request->validate(['id'=>'required|uuid','recipient_id'=>'required|integer','note'=>'nullable|string|max:500']);
        return DB::transaction(function () use ($request, $family, $data) {
            $actor = $request->user()->id;
            $row = $this->memberFamily($family, $actor, true);
            $this->requireSync($row);
            $key = ['family_id'=>$family,'id'=>$data['id']];
            $existing = DB::table('handoffs')->where($key)->first();
            if ($existing) {
                abort_unless((int)$existing->sender_id === $actor, 409, 'Handoff ID already used.');
                return $this->handoff($existing);
            }
            $recipient = (int)$data['recipient_id'];
            abort_unless((int)$row->on_duty_id === $actor, 409, 'Only the caregiver on duty can pass care.');
            abort_unless($recipient !== $actor && $this->isMember($family, $recipient), 422, 'Select a connected caregiver.');
            abort_if(DB::table('handoffs')->where('family_id',$family)->whereNull('accepted_at')->exists(), 409, 'A handoff is already waiting.');
            $snapshot = [];
            foreach (['feed','diaper','sleep'] as $type) {
                $event = $this->events($family)->where('type',$type)->where('deleted',false)
                    ->when($type === 'sleep', fn ($q) => $q->orderByRaw('CASE WHEN ended_at IS NULL THEN 0 ELSE 1 END'))
                    ->orderByDesc('started_at')->orderByDesc('revision')->first();
                $snapshot[$type] = $event ? $this->event($event) : null;
            }
            DB::table('handoffs')->insert($key + ['sender_id'=>$actor,'recipient_id'=>$recipient,
                'snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR),'note'=>$data['note'] ?? null,'created_at'=>now()]);
            return $this->handoff(DB::table('handoffs')->where($key)->first());
        }, 3);
    }

    public function acceptHandoff(Request $request, string $family, string $handoff): array
    {
        return DB::transaction(function () use ($request, $family, $handoff) {
            $actor = $request->user()->id;
            $row = $this->memberFamily($family, $actor, true);
            $this->requireSync($row);
            $query = DB::table('handoffs')->where(['family_id'=>$family,'id'=>$handoff]);
            $item = $query->first();
            abort_unless($item, 404);
            abort_unless((int)$item->recipient_id === $actor, 403, 'Only the receiving caregiver can accept.');
            if (!$item->accepted_at) {
                $item->accepted_at = now()->toDateTimeString();
                $query->update(['accepted_at'=>$item->accepted_at]);
                DB::table('families')->where('id',$family)->update(['on_duty_id'=>$actor,'on_duty_since'=>$item->accepted_at]);
            }
            return $this->handoff($item);
        }, 3);
    }

    private function handoff(stdClass $row): array
    {
        return ['id'=>$row->id,'sender_id'=>(int)$row->sender_id,'recipient_id'=>(int)$row->recipient_id,
            'snapshot'=>json_decode($row->snapshot,true),'note'=>$row->note,'created_at'=>$this->iso($row->created_at),
            'accepted_at'=>$this->iso($row->accepted_at)];
    }

    private function events(string $family)
    {
        return DB::table('care_events')->join('users','users.id','=','care_events.author_id')
            ->where('care_events.family_id',$family)->select('care_events.*','users.name as author');
    }

    private function event(stdClass $row): array
    {
        return ['id'=>$row->id,'type'=>$row->type,'started_at'=>$this->iso($row->started_at),'ended_at'=>$this->iso($row->ended_at),
            'revision'=>(int)$row->revision,'deleted'=>(bool)$row->deleted,'author'=>$row->author] + json_decode($row->details,true);
    }

    private function iso(?string $value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }

    private function family(string $id, bool $lock = false): stdClass
    {
        $query = DB::table('families')->where('id',$id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);
        return $row;
    }

    private function isMember(string $family, int $actor): bool
    {
        return DB::table('family_members')->where(['family_id'=>$family,'user_id'=>$actor])->exists();
    }

    private function memberFamily(string $family, int $actor, bool $lock = false): stdClass
    {
        abort_unless($this->isMember($family, $actor), 404);
        return $this->family($family, $lock);
    }

    private function testEnabled(): bool
    {
        return app()->environment('local','testing') && config('baby.test_upgrades') === true;
    }

    private function requireSync(stdClass $family): void
    {
        abort_unless($family->test_sync && $this->testEnabled(), 403, 'Family Sync is paused. Your local log is still available.');
    }

    private function familyState(stdClass $family, int $actor): array
    {
        return ['id'=>$family->id,'baby_name'=>$family->baby_name,'owner'=>(int)$family->owner_id === $actor,
            'test_upgrade_available'=>$this->testEnabled(),'sync_enabled'=>(bool)$family->test_sync && $this->testEnabled(),
            'on_duty_id'=>(int)$family->on_duty_id,'on_duty_since'=>$this->iso($family->on_duty_since),
            'members'=>DB::table('family_members')->join('users','users.id','=','family_members.user_id')
                ->where('family_id',$family->id)->orderBy('users.id')->get(['users.id','users.name'])];
    }
}
