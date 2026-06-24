# Ubuntu production deploy

This setup removes quick tunnels. Nginx terminates HTTPS, PHP-FPM serves Laravel,
Redis runs cache/session/queue, Supervisor keeps queue workers and Reverb alive.

## 1. Server packages

```bash
sudo apt update
sudo apt install -y nginx mysql-server redis-server supervisor unzip git curl
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-gd
```

Install Composer and Node.js if they are not present.

## 2. Upload project

Recommended path:

```bash
sudo mkdir -p /var/www/crm-omnichannel
sudo chown -R $USER:www-data /var/www/crm-omnichannel
```

Upload or `git clone` the project into `/var/www/crm-omnichannel`.

## 3. Environment

```bash
cd /var/www/crm-omnichannel
cp .env.production.example .env
php artisan key:generate
```

Edit `.env`:

- `APP_URL=https://your-domain.com`
- `DB_*`
- `REVERB_PUBLIC_HOST=your-domain.com`
- `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_VERIFY_TOKEN`
- `FACEBOOK_REDIRECT_URI="${APP_URL}/auth/facebook/callback"`

Meta App:

- App Domains: `your-domain.com`
- OAuth Redirect URI: `https://your-domain.com/auth/facebook/callback`
- Webhook Callback URL: `https://your-domain.com/api/webhook/facebook`

## 4. Install dependencies and build assets

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache public/storage
```

## 5. Nginx

Copy `deploy/nginx/crm-omnichannel.conf` to:

```bash
sudo cp deploy/nginx/crm-omnichannel.conf /etc/nginx/sites-available/crm-omnichannel
sudo ln -s /etc/nginx/sites-available/crm-omnichannel /etc/nginx/sites-enabled/crm-omnichannel
```

Replace every `crm.example.com` with your real domain.

Install SSL:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
sudo nginx -t
sudo systemctl reload nginx
```

## 6. Supervisor

```bash
sudo cp deploy/supervisor/crm-queue.conf /etc/supervisor/conf.d/crm-queue.conf
sudo cp deploy/supervisor/crm-reverb.conf /etc/supervisor/conf.d/crm-reverb.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## 7. Facebook webhook subscribe

After `.env` uses the final HTTPS domain:

```bash
php artisan optimize:clear
php artisan facebook:resubscribe-pages
```

Reconnect the Facebook page in Admin if the old page token came from local/tunnel testing.

## 8. Deploy update command

Run this after each code upload:

```bash
cd /var/www/crm-omnichannel
git pull origin ubuntu
bash scripts/deploy-ubuntu.sh
```

Local workflow:

```bash
git checkout ubuntu
git add .
git commit -m "Your change"
git push origin ubuntu
```
