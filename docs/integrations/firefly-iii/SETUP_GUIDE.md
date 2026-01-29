# Firefly III Setup Guide

Complete step-by-step instructions for setting up the Firefly III integration with WordPress NV oOS.

---

## Prerequisites Checklist

Before starting, ensure you have:

- [ ] WordPress 6.0+ installed and running
- [ ] NV oOS plugin installed and activated (Base version or higher)
- [ ] PHP 7.4+ on WordPress server
- [ ] Ability to install Firefly III (Docker or web hosting)
- [ ] HTTPS enabled (recommended for security)
- [ ] Admin access to both WordPress and Firefly III

---

## Part 1: Installing Firefly III

Choose the installation method that best fits your infrastructure:

### Option A: Docker Installation (Recommended)

**Advantages**: Easy, isolated, portable, quick setup

**Requirements**: Docker installed on your server

**Steps**:

1. **Create a docker-compose.yml file**:

```yaml
version: '3'

services:
  firefly_iii_app:
    image: fireflyiii/core:latest
    restart: unless-stopped
    volumes:
      - firefly_iii_upload:/var/www/html/storage/upload
      - firefly_iii_db:/var/www/html/storage/database
    ports:
      - "8080:8080"
    environment:
      - APP_KEY=CHANGEME_32_RANDOM_CHARACTERS_EXACTLY
      - DB_CONNECTION=sqlite
      - APP_URL=http://localhost:8080
      - TRUSTED_PROXIES=**
      - LOG_CHANNEL=stack
      - APP_LOG_LEVEL=notice
      - AUDIT_LOG_LEVEL=emergency
    
volumes:
  firefly_iii_upload:
  firefly_iii_db:
```

2. **Generate APP_KEY**:

```bash
# Linux/Mac
APP_KEY=$(head /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32)
echo $APP_KEY

# Or manually generate a 32-character random string
```

3. **Update docker-compose.yml** with your APP_KEY

4. **Start Firefly III**:

```bash
docker-compose up -d
```

5. **Access Firefly III**:
   - URL: `http://localhost:8080` (or your server IP)
   - Follow the setup wizard
   - Create your admin account

6. **Verify Installation**:

```bash
docker ps  # Should show firefly_iii_app running
docker logs firefly_iii_app  # Check for errors
```

### Option B: Self-Hosted Installation

**Advantages**: Full control, no Docker needed

**Requirements**: 
- PHP 8.1 or higher
- MySQL 8.0+ or PostgreSQL 10+
- Composer
- Web server (Apache/Nginx)

**Steps**:

1. **Install dependencies** (Ubuntu/Debian example):

```bash
sudo apt update
sudo apt install -y php8.1 php8.1-cli php8.1-fpm php8.1-mysql \
  php8.1-curl php8.1-gd php8.1-mbstring php8.1-xml php8.1-zip \
  php8.1-bcmath php8.1-intl mysql-server nginx composer
```

2. **Create database**:

```bash
sudo mysql
CREATE DATABASE firefly;
CREATE USER 'firefly'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON firefly.* TO 'firefly'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

3. **Download Firefly III**:

```bash
cd /var/www
sudo git clone https://github.com/firefly-iii/firefly-iii.git
cd firefly-iii
sudo composer install --no-dev --optimize-autoloader
```

4. **Configure environment**:

```bash
sudo cp .env.example .env
sudo nano .env
```

Edit these values:
```
APP_ENV=production
APP_URL=https://firefly.yourdomain.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=firefly
DB_USERNAME=firefly
DB_PASSWORD=strong_password_here
```

5. **Generate application key**:

```bash
sudo php artisan key:generate
```

6. **Run database migrations**:

```bash
sudo php artisan migrate --seed
sudo php artisan firefly-iii:upgrade-database
sudo php artisan passport:install
```

7. **Set permissions**:

```bash
sudo chown -R www-data:www-data /var/www/firefly-iii
sudo chmod -R 755 /var/www/firefly-iii/storage
```

8. **Configure Nginx** (example):

```nginx
server {
    listen 80;
    server_name firefly.yourdomain.com;
    root /var/www/firefly-iii/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

9. **Restart web server**:

```bash
sudo systemctl restart nginx
sudo systemctl restart php8.1-fpm
```

10. **Access and complete setup wizard**

### Option C: Managed Hosting

Some hosting providers offer one-click Firefly III installation:
- Check your hosting provider's marketplace
- Look for "Firefly III" in app installers
- Follow provider-specific instructions

---

## Part 2: Configure Firefly III

### Initial Setup

1. **Access your Firefly III URL**
2. **Complete the setup wizard**:
   - Create admin account (email + password)
   - Choose your currency
   - Configure basic settings

3. **Create test data** (optional but recommended):
   - Create a checking account
   - Add a few sample transactions
   - Create 2-3 categories (e.g., Groceries, Utilities, Entertainment)
   - This helps verify the WordPress integration later

### Generate Personal Access Token

This is the key that allows WordPress to connect to Firefly III.

**Steps**:

1. Log into Firefly III admin panel

2. Navigate to: **Options** (gear icon) → **Profile** → **OAuth** tab

3. Scroll to **"Personal Access Tokens"** section

4. Click **"Create New Token"** button

5. In the popup:
   - **Name**: Enter `WordPress NV oOS Integration`
   - Click **Create**

6. **IMPORTANT**: Copy the token immediately!
   ```
   Example token:
   eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJhdWQiOiI...
   ```
   - This is shown **only once**
   - Store it somewhere safe temporarily
   - You'll need it in the next step

7. **Verify**: The token should now appear in your list with:
   - Name: WordPress NV oOS Integration
   - Created date
   - Last used: Never (initially)

---

## Part 3: Configure WordPress

### Settings Method (Simple Setup)

Use this if you have one Firefly III instance for all users.

**Steps**:

1. **Navigate to Settings**:
   - WordPress Admin → **Settings** → **NV oOS**

2. **Find Firefly III section** (scroll down or use tabs)

3. **Enter configuration**:
   
   **API URL**:
   ```
   http://localhost:8080                    # Docker on same server
   https://firefly.yourdomain.com           # Self-hosted
   https://firefly.yourdomain.com/          # Trailing slash is optional
   ```

   **Personal Access Token**:
   ```
   eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...  # Paste full token
   ```

4. **Test connection** (if available):
   - Click **Test Connection** button
   - Should show: ✅ "Successfully connected to Firefly III"
   - If error: Check URL and token, see [Troubleshooting](#troubleshooting)

5. **Save Settings**:
   - Click **Save Changes**
   - Settings are now active

### Remote Sites Method (Advanced Setup)

Use this if you need:
- Multiple Firefly III instances
- Per-user Firefly III connections
- More control over connections

**Steps**:

1. **Navigate to Remote Sites**:
   - WordPress Admin → **NV oOS** → **Remote Sites**

2. **Add New Connection**:
   - Click **Add Connection** button

3. **Configure Connection**:

   **Connection Name**: `My Personal Firefly III`

   **Connection Type**: Select `Firefly III` from dropdown

   **API URL**: `https://firefly.yourdomain.com`

   **Access Token**: Paste your Personal Access Token

   **Enabled**: ✅ Check this box

   **Description** (optional): `Main Firefly III instance for financial tracking`

4. **Test Connection**:
   - Click **Test** button (if available)
   - Verify connection succeeds

5. **Save Connection**:
   - Click **Save** or **Create Connection**
   - Connection is now available

6. **Note the Connection ID**:
   - After saving, you'll see a connection ID (e.g., `firefly-main-001`)
   - Tools can use this: `connection_id` parameter

---

## Part 4: Verify Integration

### Test with AI Assistant

**Test 1: List Accounts**

```
User Message: "Show me all my Firefly III accounts"

Expected Result:
✅ AI lists your accounts with names and balances
✅ Shows account types (asset, expense, revenue)
```

**Test 2: Query Transactions**

```
User Message: "What transactions did I have last month in Firefly III?"

Expected Result:
✅ AI lists recent transactions
✅ Shows dates, amounts, categories
✅ Grouped or filtered as requested
```

**Test 3: Create Transaction**

```
User Message: "Create a $25 grocery expense in Firefly III"

Expected Result:
✅ AI creates transaction successfully
✅ Confirms creation with transaction ID
✅ Transaction appears in Firefly III
```

**Test 4: Generate Chart**

```
User Message: "Show me an expense breakdown chart from Firefly III"

Expected Result:
✅ AI generates Chart.js visualization
✅ Chart shows expenses by category
✅ Interactive with tooltips
```

### Verify in WordPress Admin

1. **Check Tool Availability**:
   - Go to **NV oOS** → **Tools** (or similar)
   - Look for Firefly III tools in the list
   - Should show 7 tools:
     - firefly_get_accounts
     - firefly_get_transactions
     - firefly_get_budgets
     - firefly_get_categories
     - firefly_get_bills
     - firefly_create_transaction
     - firefly_chart_expenses

2. **Check Logs** (if available):
   - Look for Firefly III API calls in system logs
   - Verify no authentication errors
   - Check for successful API responses

---

## Part 5: Security Hardening

### Recommended Security Measures

1. **Use HTTPS**:
   ```
   ✅ WordPress: https://yoursite.com
   ✅ Firefly III: https://firefly.yourdomain.com
   ```

2. **Firewall Rules**:
   - If Firefly III and WordPress are on same server:
     ```bash
     # Only allow WordPress to access Firefly III
     sudo ufw allow from <wordpress-ip> to any port 8080
     ```

3. **Token Rotation**:
   - Regenerate tokens every 90 days
   - Revoke old tokens in Firefly III
   - Update WordPress configuration

4. **Access Control**:
   - Limit WordPress users who can access financial tools
   - Use WordPress roles/capabilities
   - Consider per-user Remote Sites connections

5. **Monitoring**:
   - Enable WordPress activity logging
   - Monitor Firefly III access logs
   - Set up alerts for unusual activity

---

## Troubleshooting

See [TROUBLESHOOTING.md](./TROUBLESHOOTING.md) for detailed solutions.

### Quick Fixes

**Error: "API URL not configured"**
- Solution: Enter API URL in Settings → NV oOS

**Error: "Personal Access Token is not configured"**
- Solution: Enter token in Settings → NV oOS

**Error: "API returned error (status 401)"**
- Solution: Token is invalid or expired, generate new one

**Error: "Connection timeout"**
- Solution: Check Firefly III is running and accessible

**Tools not appearing**
- Solution: Check base version mode is disabled
- Solution: Verify settings are saved

---

## Next Steps

After successful setup:

1. **Explore Tools**: Try all 7 Firefly III tools with your AI assistant
2. **Create Workflows**: Set up common prompts for daily use
3. **Consider Pro**: Explore Financial Planner Toolkit integration (Pro addon)
4. **Backup**: Ensure both WordPress and Firefly III are backed up regularly

---

## Getting Help

- **Plugin Issues**: [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- **Firefly III Issues**: [Firefly III GitHub](https://github.com/firefly-iii/firefly-iii/issues)
- **Documentation**: Check [troubleshooting guide](./TROUBLESHOOTING.md)

---

**Setup Time**: 30-60 minutes (including Firefly III installation)  
**Difficulty**: Intermediate  
**Last Updated**: January 2026
