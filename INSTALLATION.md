# Gate Management System - Installation Guide

## System Requirements

### Server Requirements
- **PHP**: 7.4 or higher (8.0+ recommended)
- **MySQL**: 5.7 or higher (8.0+ recommended)
- **Web Server**: Apache 2.4+ or Nginx 1.18+
- **Storage**: Minimum 500MB free space (1GB+ recommended)
- **Memory**: 512MB RAM minimum (1GB+ recommended)

### PHP Extensions Required
- PDO
- PDO MySQL
- GD (for image processing)
- ZIP (for backup functionality)
- cURL
- JSON
- OpenSSL
- Fileinfo

## Installation Steps

### 1. Download and Extract Files
```bash
# Download the system files
# Extract to your web server directory
unzip gate-management-system.zip
cd gate-management-system/
```

### 2. Set File Permissions
```bash
# Make directories writable
chmod 755 uploads/
chmod 755 uploads/photos/
chmod 755 uploads/photos/visitors/
chmod 755 backups/
chmod 644 config/database.php

# Set proper ownership (adjust as needed)
chown -R www-data:www-data .
```

### 3. Database Setup

#### Create Database
```sql
CREATE DATABASE gate_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gate_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON gate_management.* TO 'gate_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Import Database Schema
```bash
mysql -u gate_user -p gate_management < vxjtgclw_security.sql
```

### 4. Configure Database Connection

Edit `config/database.php`:
```php
private $host = 'localhost';
private $db_name = 'gate_management';
private $username = 'gate_user';
private $password = 'your_secure_password';
```

### 5. Web Server Configuration

#### Apache (.htaccess)
The system includes a comprehensive `.htaccess` file. Ensure mod_rewrite is enabled:
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Nginx
Add this to your server block:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/gate-management;
    index index.php;

    # Security headers
    add_header X-Frame-Options DENY;
    add_header X-Content-Type-Options nosniff;
    add_header X-XSS-Protection "1; mode=block";

    # PHP handling
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to sensitive files
    location ~ /\. {
        deny all;
    }
    
    location /config/ {
        deny all;
    }
    
    location /backups/ {
        deny all;
    }
}
```

### 6. SSL Configuration (Recommended)

#### Using Certbot (Let's Encrypt)
```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d your-domain.com
```

### 7. Initial System Setup

#### Access the System
1. Navigate to `https://your-domain.com/login.php`
2. Login with default credentials:
   - **Operator Code**: `ADMIN001`
   - **Password**: `password`

#### Change Default Password
1. Go to Settings > Operators
2. Edit the Admin user
3. Set a strong password
4. Save changes

### 8. System Configuration

#### Basic Settings
1. Go to Settings
2. Configure:
   - System Name
   - Theme Colors
   - Session Timeout
   - Email Notifications (if needed)

#### Create Departments
1. Go to Settings > Manage Departments
2. Add your organization's departments
3. Set contact information

#### Create Additional Operators
1. Go to Settings > Operators
2. Add operators as needed
3. Assign appropriate roles (Admin/Operator)

## Security Hardening

### 1. File Permissions
```bash
# Restrict sensitive directories
chmod 700 config/
chmod 700 backups/
chmod 755 uploads/

# Protect important files
chmod 600 config/database.php
chmod 644 .htaccess
```

### 2. Database Security
```sql
-- Remove default accounts
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

-- Set strong passwords
ALTER USER 'root'@'localhost' IDENTIFIED BY 'strong_root_password';

-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

FLUSH PRIVILEGES;
```

### 3. PHP Security
Edit `php.ini`:
```ini
; Hide PHP version
expose_php = Off

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen

; Set secure session settings
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_only_cookies = 1

; File upload limits
file_uploads = On
upload_max_filesize = 10M
post_max_size = 10M
max_file_uploads = 5

; Error handling
display_errors = Off
log_errors = On
error_log = /var/log/php_errors.log
```

### 4. Firewall Configuration
```bash
# UFW (Ubuntu)
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# Fail2ban for brute force protection
sudo apt install fail2ban
```

### 5. Regular Backups
Set up automated backups:
```bash
# Add to crontab (crontab -e)
# Daily database backup at 2 AM
0 2 * * * /usr/bin/mysqldump -u gate_user -p'password' gate_management > /backups/gate_db_$(date +\%Y\%m\%d).sql

# Weekly full backup at 3 AM on Sundays
0 3 * * 0 tar -czf /backups/gate_full_$(date +\%Y\%m\%d).tar.gz /var/www/gate-management/
```

## Maintenance

### Daily Tasks
- [ ] Check system health monitor
- [ ] Review activity logs
- [ ] Monitor disk space
- [ ] Check for failed logins

### Weekly Tasks
- [ ] Create system backup
- [ ] Review visitor data
- [ ] Check system performance
- [ ] Update statistics

### Monthly Tasks
- [ ] Review security logs
- [ ] Update system documentation
- [ ] Performance optimization
- [ ] Security audit

## Troubleshooting

### Common Issues

#### 1. Database Connection Failed
```bash
# Check MySQL service
sudo systemctl status mysql

# Check database credentials
mysql -u gate_user -p

# Verify database exists
SHOW DATABASES;
```

#### 2. File Permission Errors
```bash
# Fix ownership
sudo chown -R www-data:www-data /var/www/gate-management/

# Fix permissions
sudo find /var/www/gate-management/ -type d -exec chmod 755 {} \;
sudo find /var/www/gate-management/ -type f -exec chmod 644 {} \;
```

#### 3. PHP Errors
```bash
# Check PHP error log
sudo tail -f /var/log/php_errors.log

# Check Apache error log
sudo tail -f /var/log/apache2/error.log

# Verify PHP extensions
php -m | grep -E "(pdo|gd|zip)"
```

#### 4. Upload Issues
```bash
# Check upload directory permissions
ls -la uploads/photos/visitors/

# Verify PHP upload settings
php -i | grep -E "(upload_max_filesize|post_max_size|file_uploads)"
```

### Performance Optimization

#### 1. Database Optimization
```sql
-- Analyze table performance
ANALYZE TABLE visitors, gate_logs, pre_registrations;

-- Optimize tables
OPTIMIZE TABLE visitors, gate_logs, pre_registrations;

-- Check slow queries
SHOW PROCESSLIST;
```

#### 2. Web Server Optimization
```apache
# Enable compression (.htaccess already includes this)
# Enable browser caching
# Use CDN for static assets
```

#### 3. PHP Optimization
```ini
; Increase memory limit if needed
memory_limit = 256M

; Enable OPcache
opcache.enable = 1
opcache.memory_consumption = 128
opcache.max_accelerated_files = 4000
```

## Monitoring and Logging

### 1. System Monitoring
- Use the built-in System Health Monitor
- Monitor disk space usage
- Check database performance
- Review error logs regularly

### 2. Activity Logging
- All user actions are logged
- Failed login attempts are tracked
- System changes are recorded
- Export logs for external analysis

### 3. Backup Monitoring
- Verify backup completion
- Test backup restoration
- Monitor backup file sizes
- Clean old backups regularly

## Updates and Upgrades

### 1. Before Updating
```bash
# Create full backup
tar -czf gate_backup_$(date +%Y%m%d).tar.gz /var/www/gate-management/

# Backup database
mysqldump -u gate_user -p gate_management > gate_db_backup_$(date +%Y%m%d).sql

# Test in staging environment first
```

### 2. Update Process
1. Download latest version
2. Compare configuration files
3. Update files (excluding config/)
4. Run any database migrations
5. Test functionality
6. Update documentation

### 3. Rollback Plan
1. Stop web server
2. Restore previous files
3. Restore database if needed
4. Start web server
5. Verify functionality

## Support and Documentation

### Getting Help
- Check the system health monitor
- Review error logs
- Consult troubleshooting guide
- Contact system administrator

### Documentation
- User Manual: Included in system
- API Documentation: Available in /docs/
- Configuration Guide: This file
- Security Guidelines: See security section

### Contributing
- Report bugs through the system
- Suggest improvements
- Follow coding standards
- Test thoroughly before submitting

## License and Legal

### System License
This system is provided under the MIT License. See LICENSE file for details.

### Data Privacy
- Visitor data is stored locally
- Follow GDPR/privacy regulations
- Implement data retention policies
- Secure data transmission

### Compliance
- Regular security audits
- Access control implementation
- Audit trail maintenance
- Data backup procedures

---

## Quick Reference

### Default Credentials
- **Username**: ADMIN001
- **Password**: password (CHANGE IMMEDIATELY)

### Important Directories
- Config: `/config/`
- Uploads: `/uploads/photos/visitors/`
- Backups: `/backups/`
- Logs: System-dependent

### Key Files
- Database Config: `config/database.php`
- Security: `.htaccess`
- Main Entry: `index.php`
- Login: `login.php`

### Support Contacts
- System Admin: admin@yourdomain.com
- Technical Support: support@yourdomain.com
- Emergency: +1 (555) 123-4567

---

**Last Updated**: <?php echo date('Y-m-d'); ?>
**Version**: 1.0.0