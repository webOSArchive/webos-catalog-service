# webOS App Catalog Backend - Installation Guide

Ubuntu 24.04 LTS with nginx and PHP-FPM

## 1. Install Required Packages

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-mysql php-mbstring php-json mariadb-server
```

## 2. Configure MariaDB

```bash
# Secure the installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE webos_catalog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'catalog_user'@'localhost' IDENTIFIED BY 'YOUR_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON webos_catalog.* TO 'catalog_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Import Database

```bash
mysql -u catalog_user -p webos_catalog < /path/to/webos-catalog-backend/migration/webos_catalog_export.sql
```

## 4. Deploy Application Files

```bash
# Copy files to web root
sudo mkdir -p /var/www/appcatalog
sudo cp -r /path/to/webos-catalog-backend/* /var/www/appcatalog/
sudo chown -R www-data:www-data /var/www/appcatalog
```

## 5. Configure Application

```bash
sudo nano /var/www/appcatalog/WebService/config.php
```

Update database credentials:
```php
'db_host' => 'localhost',
'db_name' => 'webos_catalog',
'db_user' => 'catalog_user',
'db_pass' => 'YOUR_SECURE_PASSWORD'
```

Update service_host to your domain:
```php
'service_host' => 'appcatalog.yourdomain.org',
```

## 6. Configure nginx

```bash
sudo nano /etc/nginx/sites-available/appcatalog
```

```nginx
server {
    listen 80;
    server_name appcatalog.yourdomain.org;
    root /var/www/appcatalog;
    index index.php index.html;

    # Logging
    access_log /var/log/nginx/appcatalog.access.log;
    error_log /var/log/nginx/appcatalog.error.log;

    # Main location
    location / {
        try_files $uri $uri/ =404;
    }

    # PHP handling
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Admin area - Basic Auth
    location /admin {
        auth_basic "Admin Area";
        auth_basic_user_file /etc/nginx/.htpasswd;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/var/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            include fastcgi_params;
        }
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ ^/(includes|migration)/ {
        deny all;
    }

    # Rate limit directories need to be writable
    location ~ ^/__rateLimit/ {
        deny all;
    }
}
```

Enable the site:
```bash
sudo ln -s /etc/nginx/sites-available/appcatalog /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default  # Optional: remove default site
sudo nginx -t
sudo systemctl reload nginx
```

## 7. Create Admin Password

```bash
sudo apt install -y apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd admin
# Enter password when prompted
```

## 8. Set Directory Permissions

```bash
# Rate limit directory needs to be writable
sudo mkdir -p /var/www/appcatalog/__rateLimit
sudo chown www-data:www-data /var/www/appcatalog/__rateLimit
sudo chmod 755 /var/www/appcatalog/__rateLimit
```

## 9. Configure PHP (Optional Tuning)

```bash
sudo nano /etc/php/8.3/fpm/php.ini
```

Recommended settings:
```ini
memory_limit = 256M
max_execution_time = 60
post_max_size = 8M
upload_max_filesize = 8M
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.3-fpm
```

## 10. SSL with Let's Encrypt (Recommended)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d appcatalog.yourdomain.org
```

## 11. Test Installation

```bash
# Test API
curl http://appcatalog.yourdomain.org/WebService/getMuseumMaster.php?count=1&key=test&museumVersion=web

# Test admin (should prompt for password)
curl -I http://appcatalog.yourdomain.org/admin/
```

## Troubleshooting

**500 errors:** Check nginx and PHP-FPM logs:
```bash
sudo tail -f /var/log/nginx/appcatalog.error.log
sudo tail -f /var/log/php8.3-fpm.log
```

**Database connection errors:** Verify credentials in config.php and test:
```bash
mysql -u catalog_user -p webos_catalog -e "SELECT COUNT(*) FROM apps;"
```

**Permission errors:** Ensure www-data owns the files:
```bash
sudo chown -R www-data:www-data /var/www/appcatalog
```
