
FROM php:8.3-apache

# 安裝 PHP MySQL 擴充套件
RUN docker-php-ext-install pdo_mysql mysqli

# 啟用 Apache rewrite，並確保只使用 prefork MPM
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite

# 複製網站程式
COPY . /var/www/html/

# 設定網站目錄權限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Railway PORT 設定
RUN printf '#!/bin/sh\nset -e\nPORT="${PORT:-8080}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-railway \
    && chmod +x /usr/local/bin/start-railway

EXPOSE 8080

CMD ["start-railway"]
