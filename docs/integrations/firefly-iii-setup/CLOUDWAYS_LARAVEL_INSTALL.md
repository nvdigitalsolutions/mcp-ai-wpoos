# Installing Firefly III Laravel App Directly on Cloudways

Complete guide for installing the Firefly III Laravel application on your Cloudways server.

⚠️ **Important**: This is an advanced setup. Cloudways is optimized for WordPress/PHP applications, not Laravel apps. Consider using a separate VPS for better support and flexibility.

---

## Prerequisites

- ✅ Cloudways account with an active server
- ✅ Server with at least 2GB RAM (4GB recommended)
- ✅ PHP 8.1 or 8.2 installed
- ✅ MySQL database available
- ✅ SSH and SFTP access enabled
- ✅ Basic Linux command line knowledge

---

## Method 1: Manual Laravel Installation on Cloudways (Recommended)

### Step 1: Create a Custom Application on Cloudways

1. **Log into Cloudways Dashboard**

2. **Navigate to your Server**

3. **Add a New Application**:
   - Click **Applications** tab
   - Click **Add Application**
   - Choose **Custom PHP** (not WordPress)
   - Application Name: `Firefly III`
   - Select latest PHP version (8.1 or 8.2)
   - Click **Add Application**

4. **Note Application Details**:
   - Application URL: `https://xxx-xxx-xxx-xxx.cloudwaysapps.com/`
   - Application Path: `/home/master/applications/[app-name]/`
   - Database Name, User, Password (from Access Details)

### Step 2: Prepare MySQL Database

1. **Access Database**:
   - Cloudways Dashboard → Application → **Access Details**
   - Note: Database Name, Username, Password, Host

2. **Create Database** (if needed):
   - Use phpMyAdmin or MySQL command line
   - Database is usually pre-created by Cloudways

### Step 3: Enable SSH and Install Dependencies

1. **Enable SSH Access**:
   - Cloudways Dashboard → Server → **SSH & SFTP**
   - Enable SSH access
   - Note SSH credentials

2. **SSH into Server**:
   ```bash
   ssh master@your-server-ip -p port-number
   ```

3. **Install Composer** (if not present):
   ```bash
   cd ~
   curl -sS https://getcomposer.org/installer | php
   sudo mv composer.phar /usr/local/bin/composer
   composer --version
   ```

4. **Check PHP Version**:
   ```bash
   php -v
   # Should be 8.1 or higher
   ```

### Step 4: Download and Install Firefly III

1. **Navigate to Application Directory**:
   ```bash
   cd /home/master/applications/[your-app-name]/
   ```

2. **Backup and Clear public_html**:
   ```bash
   mv public_html public_html_backup
   ```

3. **Clone Firefly III**:
   ```bash
   git clone https://github.com/firefly-iii/firefly-iii.git firefly-iii
   cd firefly-iii
   ```

4. **Install Dependencies**:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
   
   This may take 5-10 minutes.

### Step 5: Configure Environment

1. **Create Environment File**:
   ```bash
   cp .env.example .env
   nano .env
   ```

2. **Edit .env File** (key settings):

   ```ini
   # Application
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-cloudways-url.cloudwaysapps.com
   APP_KEY=  # Will generate in next step
   
   # Database (from Cloudways Access Details)
   DB_CONNECTION=mysql
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=your_db_name
   DB_USERNAME=your_db_user
   DB_PASSWORD=your_db_password
   
   # Site Owner
   SITE_OWNER=your-email@example.com
   
   # Security
   TRUSTED_PROXIES=**
   
   # Disable features that require specific setup
   CACHE_DRIVER=file
   SESSION_DRIVER=file
   QUEUE_DRIVER=sync
   
   # Logging
   LOG_CHANNEL=stack
   APP_LOG_LEVEL=notice
   ```

3. **Save and Exit** (Ctrl+X, Y, Enter)

### Step 6: Generate Application Key

```bash
php artisan key:generate
```

This will update the `APP_KEY` in your `.env` file.

### Step 7: Run Database Migrations

1. **Initialize Database**:
   ```bash
   php artisan migrate --seed
   ```
   
   Answer **yes** when prompted.

2. **Initialize Passport** (for API authentication):
   ```bash
   php artisan passport:install
   ```
   
   Save the generated tokens somewhere safe.

3. **Upgrade Database**:
   ```bash
   php artisan firefly-iii:upgrade-database
   ```

### Step 8: Set Up Public Directory

1. **Link Public Directory**:
   ```bash
   cd /home/master/applications/[your-app-name]/
   rm -rf public_html
   ln -s firefly-iii/public public_html
   ```

2. **Set Permissions**:
   ```bash
   cd firefly-iii
   chmod -R 755 storage bootstrap/cache
   chown -R master:www-data storage bootstrap/cache
   ```

### Step 9: Configure Webroot in Cloudways

1. **Cloudways Dashboard** → Application → **Settings & Packages**

2. **Scroll to Application Settings**

3. **Webroot**: Change from `/public_html` to `/public_html` (or verify it's correct)

4. **Save Changes**

### Step 10: Set Up Custom Domain (Optional)

1. **Cloudways Dashboard** → Application → **Domain Management**

2. **Add Primary Domain**:
   - Enter your domain: `firefly.yourdomain.com`
   - Click **Add Domain**

3. **Update DNS**:
   - Add A record: `firefly` → Your Cloudways server IP

4. **Enable SSL**:
   - Cloudways Dashboard → Application → **SSL Certificate**
   - Install Let's Encrypt Certificate

5. **Update .env**:
   ```bash
   nano /home/master/applications/[your-app-name]/firefly-iii/.env
   ```
   
   Change:
   ```ini
   APP_URL=https://firefly.yourdomain.com
   ```

6. **Clear Cache**:
   ```bash
   cd /home/master/applications/[your-app-name]/firefly-iii
   php artisan config:clear
   php artisan cache:clear
   ```

### Step 11: Complete Setup Wizard

1. **Access Firefly III**:
   - URL: `https://your-app.cloudwaysapps.com` or `https://firefly.yourdomain.com`

2. **Complete Setup Wizard**:
   - Create admin account
   - Configure basic settings
   - Choose currency

3. **Verify Installation**:
   - Create a test account
   - Add a sample transaction
   - Check that everything works

---

## Method 2: Using Cloudways SSH & Git Deploy (Advanced)

### Prerequisites
- Git repository with your Firefly III fork
- Cloudways Git Deployment feature enabled

### Steps

1. **Create Custom PHP Application** (as above)

2. **Enable Git Deployment**:
   - Cloudways Dashboard → Application → **Deployment via Git**
   - Connect your Git provider (GitHub/GitLab/Bitbucket)
   - Select repository: `firefly-iii/firefly-iii`
   - Branch: `main`
   - Deploy path: `/public_html`

3. **Configure Deployment Script**:
   - Add deployment commands:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force
   php artisan config:cache
   php artisan route:cache
   ```

4. **Deploy**:
   - Click **Pull** to deploy code
   - Complete remaining steps from Method 1 (env configuration, database setup)

---

## Post-Installation Configuration

### 1. Set Up Cron Jobs

Firefly III requires a cron job for recurring transactions.

**Cloudways Dashboard** → Application → **Cron Job Management**

Add cron job:
```
Command: cd /home/master/applications/[your-app-name]/firefly-iii && php artisan schedule:run >> /dev/null 2>&1
Frequency: Every Minute (* * * * *)
```

### 2. Optimize Performance

```bash
cd /home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos/firefly-iii

# Cache configuration
php artisan config:cache

# Cache routes
php artisan route:cache

# Cache views
php artisan view:cache
```

### 3. Enable Redis (Optional but Recommended)

1. **Cloudways Dashboard** → Application → **Application Settings**

2. **Enable Redis**

3. **Update .env**:
   ```ini
   CACHE_DRIVER=redis
   SESSION_DRIVER=redis
   REDIS_HOST=127.0.0.1
   REDIS_PASSWORD=null
   REDIS_PORT=6379
   ```

4. **Clear cache**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

### 4. Configure Backups

**Automated Cloudways Backups**:
- Cloudways Dashboard → Server → **Backup and Restore**
- Enable automatic backups (included in most plans)

**Manual Database Backup**:
```bash
mysqldump -u db_user -p db_name > firefly_backup_$(date +%Y%m%d).sql
```

---

## Maintenance

### Updating Firefly III

```bash
# SSH into server
cd /home/master/applications/[your-app-name]/firefly-iii

# Put in maintenance mode
php artisan down

# Pull latest code
git fetch --all
git checkout main
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader

# Run migrations
php artisan migrate --force

# Upgrade database
php artisan firefly-iii:upgrade-database

# Clear and rebuild cache
php artisan config:clear
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Exit maintenance mode
php artisan up
```

### Monitoring

1. **Check Laravel Logs**:
   ```bash
   tail -f /home/master/applications/[your-app-name]/firefly-iii/storage/logs/laravel.log
   ```

2. **Monitor Resource Usage**:
   - Cloudways Dashboard → Server → **Monitoring**
   - Check CPU, RAM, Disk usage

---

## Troubleshooting

### Issue: "500 Internal Server Error"

**Solutions**:

1. **Check Laravel Logs**:
   ```bash
   tail -50 /home/master/applications/[your-app-name]/firefly-iii/storage/logs/laravel.log
   ```

2. **Check Permissions**:
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R master:www-data storage bootstrap/cache
   ```

3. **Clear All Caches**:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   php artisan route:clear
   ```

### Issue: "Database Connection Error"

**Solutions**:

1. **Verify Database Credentials**:
   - Check `.env` file
   - Verify with Cloudways Access Details

2. **Test Database Connection**:
   ```bash
   mysql -h localhost -u db_user -p db_name
   ```

3. **Check Database Exists**:
   - Use phpMyAdmin from Cloudways dashboard

### Issue: "APP_KEY Not Set"

**Solution**:
```bash
php artisan key:generate
```

### Issue: "Composer Install Fails"

**Solutions**:

1. **Check PHP Version**:
   ```bash
   php -v  # Must be 8.1+
   ```

2. **Increase Memory Limit**:
   ```bash
   php -d memory_limit=2G /usr/local/bin/composer install --no-dev
   ```

3. **Update Composer**:
   ```bash
   composer self-update
   ```

---

## Connecting to WordPress

After Firefly III is installed on Cloudways:

### Get Personal Access Token

1. Log into Firefly III
2. **Options** → **Profile** → **OAuth**
3. Create Personal Access Token: `WordPress Integration`
4. Copy token

### Configure WordPress (on same or different Cloudways app)

1. **WordPress Admin** → **Settings** → **NV oOS**
2. **API URL**: `https://firefly.yourdomain.com` or `https://your-app.cloudwaysapps.com`
3. **Personal Access Token**: (paste token)
4. **Test Connection** → Save

---

## Performance Optimization

### 1. Enable OPcache

Cloudways enables OPcache by default. Verify:
```bash
php -i | grep opcache.enable
```

### 2. Use Redis for Caching

See "Enable Redis" section above.

### 3. Optimize Database

```bash
cd /home/master/applications/[your-app-name]/firefly-iii
php artisan optimize
```

### 4. Enable Cloudways CDN

- Cloudways Dashboard → Application → **Cloudways CDN**
- Enable CDN for static assets

---

## Security Considerations

### 1. Disable Debug Mode

In `.env`:
```ini
APP_DEBUG=false
APP_ENV=production
```

### 2. Firewall Rules

Cloudways Dashboard → Server → **Security** → **Firewall Management**
- Ensure only necessary ports are open (80, 443, SSH)

### 3. Regular Updates

- Update Firefly III monthly
- Update PHP version as needed
- Monitor security advisories

### 4. Strong Database Password

- Use Cloudways generated password (strong by default)
- Rotate passwords every 90 days

---

## Cost Estimate

**Cloudways Hosting for Firefly III + WordPress**:

| Plan | RAM | Storage | Price/Month | Suitable For |
|------|-----|---------|-------------|--------------|
| DO Basic | 1GB | 25GB | $11 | Testing only |
| DO Standard | 2GB | 50GB | $22 | Small personal use |
| DO Advanced | 4GB | 80GB | $44 | Recommended |
| DO Premium | 8GB | 160GB | $88 | Heavy usage |

**Recommendation**: 2-4GB RAM plan for Firefly III + WordPress combined

---

## When to Use Separate VPS Instead

Consider separate $5-10/mo VPS for Firefly III if:
- ✅ You want full Docker support
- ✅ You need dedicated resources
- ✅ You want easier Laravel deployment
- ✅ You prefer simpler architecture
- ✅ You want to minimize Cloudways resource usage

---

## Support Resources

**Cloudways Support**:
- Live Chat: 24/7
- Knowledge Base: https://support.cloudways.com
- Community: https://community.cloudways.com

**Firefly III Support**:
- Documentation: https://docs.firefly-iii.org
- GitHub: https://github.com/firefly-iii/firefly-iii
- Community: Reddit r/FireflyIII

**Laravel on Cloudways**:
- https://support.cloudways.com/en/articles/5129953-how-to-deploy-a-laravel-application-on-cloudways

---

## Quick Reference Commands

```bash
# Application directory
cd /home/master/applications/[app-name]/firefly-iii

# View logs
tail -f storage/logs/laravel.log

# Clear cache
php artisan cache:clear

# Run migrations
php artisan migrate

# Update Firefly
git pull && composer install --no-dev && php artisan migrate

# Check status
php artisan about
```

---

**Last Updated**: January 2026  
**Tested On**: Cloudways DO/Vultr/Linode servers  
**PHP Version**: 8.1.x, 8.2.x  
**Firefly III Version**: 6.1.x
