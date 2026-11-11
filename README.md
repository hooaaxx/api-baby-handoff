# Baby Handoff Reverb on EC2

These steps use the existing Laravel API checkout at `/var/www/baby-handoff-api`, PHP 8.5, and the API's HTTPS Nginx site. Run Composer after pulling the API update.

```bash
cd /var/www/baby-handoff-api
composer install --no-dev --optimize-autoloader
composer check-platform-reqs --no-dev
composer show laravel/reverb
```

Set these values in the API `.env`. Use your actual API hostname. Generate separate values for the ID, key, and secret with `openssl rand -hex 16`, `openssl rand -hex 16`, and `openssl rand -hex 32`.

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=replace_with_random_id
REVERB_APP_KEY=replace_with_random_key
REVERB_APP_SECRET=replace_with_random_secret
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT=8080
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_ALLOWED_ORIGIN=api.example.com
```

Keep the secret in `.env` on EC2. Android receives the app key through the authenticated API. Keep your existing `APP_KEY` and database settings. For the current two-phone test, Family Sync requires `APP_ENV=testing` and `BABY_HANDOFF_TEST_UPGRADES=true`. Keep `APP_DEBUG=false` and limit HTTPS access to your test networks while the bypass is enabled. Add this block inside the existing HTTPS `server` block for your API.

```nginx
location ^~ /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 90s;
}
```

The phone connects through HTTPS port 443 on the same hostname it uses for the API. Laravel publishes directly to Reverb on loopback. Keep port 8080 internal to EC2.

Create `/etc/systemd/system/baby-handoff-reverb.service` with the following contents. The service uses the same user as the default Ubuntu PHP-FPM pool, which needs read access to `.env` and write access to Laravel's `storage` directory.

```ini
[Unit]
Description=Baby Handoff Laravel Reverb
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/baby-handoff-api
ExecStart=/usr/bin/php8.5 artisan reverb:start
Restart=always
RestartSec=3
LimitNOFILE=4096

[Install]
WantedBy=multi-user.target
```

Apply the changes and check both services.

```bash
cd /var/www/baby-handoff-api
php artisan config:cache
sudo systemctl daemon-reload
sudo systemctl enable --now baby-handoff-reverb
sudo systemctl reload php8.5-fpm
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl status baby-handoff-reverb --no-pager
```

When deploying API updates, rebuild Laravel's configuration cache, reload PHP-FPM, and restart `baby-handoff-reverb` so both processes load the new code and settings.

## Test on two phones

Build or install the updated Android app. Sign into the same family on both phones, keep both apps open, and log a feed on one phone. The other phone should update without a swipe. Pass care, accept it on the second phone, and verify both screens show the new caregiver. Turn off Wi-Fi on one phone, add an entry, and reconnect to verify catch-up. Reverb carries a change signal, and the authenticated API still handles the care data. The app falls back to quiet polling about once a minute while disconnected.

To check the WebSocket route from a test network, substitute the real hostname and `REVERB_APP_KEY` below. Expect `101 Switching Protocols`. The request can time out after printing `101` because the connection stays open.

```bash
curl -i --http1.1 --max-time 5 \
  -H 'Connection: Upgrade' -H 'Upgrade: websocket' \
  -H 'Sec-WebSocket-Version: 13' \
  -H 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==' \
  -H 'Origin: https://api.example.com' \
  'https://api.example.com/app/YOUR_REVERB_APP_KEY?protocol=7&client=check&version=1.0'
```

If you need to turn Reverb off, set `BROADCAST_CONNECTION=null`, rebuild the config cache, reload PHP-FPM, and stop `baby-handoff-reverb`. The updated app will continue syncing through its existing API.
