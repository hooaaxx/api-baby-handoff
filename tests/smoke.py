"""Use only against a fresh local test database."""
import json
import sys
import subprocess
from pathlib import Path
import urllib.error
import urllib.request
from uuid import uuid4

base = sys.argv[1].rstrip('/')
enabled = '--disabled' not in sys.argv[2:]
reverb = '--reverb' in sys.argv[2:]

def request(path, token=None, data=None, expected=200):
    if base == 'kernel':
        local = subprocess.run(['php', str(Path(__file__).with_name('request.php'))],
            input=json.dumps(dict(path=path, token=token, data=data)), text=True, capture_output=True, check=True)
        response = json.loads(local.stdout)
        assert response['status'] == expected, (path, expected, response['status'], response['body'][:1500])
        return json.loads(response['body']) if response['body'] else None
    headers = {'Accept': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    if data is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(base + path, data=None if data is None else json.dumps(data).encode(), headers=headers)
    try:
        res = urllib.request.urlopen(req, timeout=12)
    except urllib.error.HTTPError as error:
        res = error
    body = res.read().decode()
    assert res.status == expected, (path, expected, res.status, body[:1200])
    return json.loads(body) if body else None

def account(name):
    return request('/auth/register', data={'name': name, 'email': f'{name.lower()}-{uuid4().hex[:6]}@example.com',
        'password': 'Local-test-password-92'}, expected=201)

def uid():
    return str(uuid4())

def operation(id, event=None, revision=0):
    return {'id': id, 'op_id': uid(), 'base_revision': revision,
        'operation': 'delete' if event is None else 'upsert', 'event': event}

def sync(path, token, changes, cursor=0):
    return request(path + '/sync', token, {'cursor': cursor, 'changes': changes})

mama, papa, other = (account(name) for name in ['Mama', 'Papa', 'Visitor'])
mt, pt, ot = (user['token'] for user in [mama, papa, other])
family = request('/families', mt, {'baby_name': 'Baby'}, 201)
path = '/families/' + family['id']
request(path + '/status', ot, expected=404)
request(path + '/sync', mt, {'cursor': 0, 'changes': []}, 403)
if reverb:
    auth_path = path + '/realtime/auth'
    socket = {'socket_id': '123.456'}
    request(auth_path, mt, socket, 403)
if not enabled:
    request(path + '/billing/test-activate', mt, {}, 404)
    print('Verified: test upgrade and sync disabled outside the opted-in test environment.')
    sys.exit()
assert request(path + '/billing/test-activate', mt, {})['sync_enabled']
invite = request(path + '/invites', mt, {})
joined = request('/invites/redeem', pt, {'code': invite['code']})
assert len(joined['members']) == 2
if reverb:
    assert joined['realtime']['key'], joined
    request(auth_path, data=socket, expected=401)
    request(auth_path, ot, socket, 404)
    request(auth_path, pt, {'socket_id': 'invalid'}, 422)
    assert request(auth_path, pt, socket)['auth'].startswith(joined['realtime']['key'] + ':')
request('/invites/redeem', ot, {'code': invite['code']}, 422)
request(path + '/billing/test-activate', pt, {}, 403)
feed_id, diaper_id, sleep_id = uid(), uid(), uid()
feed = {'type': 'feed', 'kind': 'bottle', 'amount': 60, 'started_at': '2026-11-11T02:18:00+08:00'}
diaper = {'type': 'diaper', 'kind': 'wet', 'started_at': '2026-11-11T02:30:00+08:00'}
sleep = {'type': 'sleep', 'started_at': '2026-11-11T02:45:00+08:00'}
pending = [operation(feed_id, feed), operation(diaper_id, diaper), operation(sleep_id, sleep)]
assert all(r['status'] == 'applied' for r in sync(path, mt, pending)['results'])
assert len(sync(path, mt, pending)['events']) == 3
pulled = sync(path, pt, [])
assert pulled['events'][0]['amount'] == 60 and pulled['events'][0]['author'] == 'Mama'
assert pulled['events'][0]['started_at'] == '2026-11-10T18:18:00+00:00'
assert sync(path, pt, [operation(uid(), sleep)])['results'][0]['status'] == 'invalid'
handoff_id = uid()
handoff = request(path + '/handoffs', mt, {'id': handoff_id, 'recipient_id': papa['user']['id'], 'note': 'Clean bottle on the rack.'})
assert handoff['snapshot']['feed']['amount'] == 60
assert request(path + '/handoffs/current', pt)['handoff']['accepted_at'] is None
request(path + '/handoffs/' + handoff_id + '/accept', mt, {}, 403)
accepted = request(path + '/handoffs/' + handoff_id + '/accept', pt, {})
assert accepted['accepted_at']
assert request(path + '/handoffs/' + handoff_id + '/accept', pt, {}) == accepted
assert request(path + '/status', mt)['on_duty_id'] == papa['user']['id']
stale = sync(path, pt, [operation(feed_id, dict(feed, amount=70))])
assert stale['results'][0]['status'] == 'conflict'
revision = stale['results'][0]['current']['revision']
edit = sync(path, pt, [operation(feed_id, dict(feed, amount=65), revision)])
assert edit['results'][0]['status'] == 'applied'
assert request(path + '/handoffs/current', mt)['handoff']['snapshot']['feed']['amount'] == 60
delete = sync(path, pt, [operation(feed_id, revision=edit['results'][0]['revision'])])
revision = delete['results'][0]['revision']
assert next(e for e in sync(path, mt, [], pulled['cursor'])['events'] if e['id'] == feed_id)['deleted']
restored = sync(path, pt, [operation(feed_id, feed, revision)])
assert restored['results'][0]['status'] == 'applied'
request('/auth/logout', pt, {}, 204)
request(path + '/status', pt, expected=401)
print('Passed: gated upgrade, two accounts, invite, offline batch replay, conflict, active nap, handoff acceptance, delete/undo, and logout.')
