FROM php:8.5-apache

# 安裝 MySQL 擴充套件
RUN docker-php-ext-install pdo_mysql mysqli

# 啟用 rewrite
RUN a2enmod rewrite

# 確保 Apache 只載入 prefork MPM
RUN set -eux; \
    rm -f /etc/apache2/mods-enabled/mpm_event.load; \
    rm -f /etc/apache2/mods-enabled/mpm_worker.load; \
    ln -sf ../mods-available/mpm_prefork.load \
        /etc/apache2/mods-enabled/mpm_prefork.load; \
    apache2ctl -t

# 複製 PHP 網站
COPY . /var/www/html/

# 設定網站檔案權限
RUN chown -R www-data:www-data /var/www/html

# Railway 動態 PORT
RUN printf '#!/bin/sh\nset -e\nPORT="${PORT:-8080}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-railway \
    && chmod +x /usr/local/bin/start-railway

EXPOSE 8080

CMD ["start-railway"]
