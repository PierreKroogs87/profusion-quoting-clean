<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    # Prevent infinite loop by excluding specific PHP files
    RewriteCond %{REQUEST_URI} !^/(index\.php|login_management/login\.php|login_management/auth\.php|broker_summary\.php|dashboard\.php)$ [NC]
    # Allow access to static assets (e.g., CSS, JS, images, fonts)
    RewriteCond %{REQUEST_URI} !\.(css|js|png|jpg|jpeg|gif|ico|pdf|woff|woff2|svg|ttf|otf)$ [NC]
    # Allow access to images directory
    RewriteCond %{REQUEST_URI} !^/images/.*$ [NC]
    # Allow access to insurance-pwa directory for static assets
    RewriteCond %{REQUEST_URI} !^/insurance-pwa/.*\.(css|js|png|jpg|jpeg|gif|ico|pdf|woff|woff2|svg|ttf|otf)$ [NC]
    # Redirect all other requests to index.php
    RewriteRule ^(.*)$ index.php [L,R=302]
</IfModule>

# Protect .htaccess from being accessed
<Files ".htaccess">
    Require all denied
</Files>