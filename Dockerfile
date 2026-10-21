FROM php:8.5-apache

# 安裝 MySQL 擴充套件
RUN docker-php-ext-install pdo_mysql mysqli

# 啟用 rewrite
RUN a2enmod rewrite
COPY docker/uploads-security.conf /etc/apache2/conf-available/uploads-security.conf
RUN a2enconf uploads-security
RUN printf 'upload_max_filesize=100M\npost_max_size=110M\nmax_execution_time=300\n' > /usr/local/etc/php/conf.d/uploads.ini

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

# Railway 啟動時再次檢查 Apache，避免只在建置時檢查。
COPY docker/start-railway.sh /usr/local/bin/start-railway
RUN sed -i 's/\r$//' /usr/local/bin/start-railway \
    && chmod +x /usr/local/bin/start-railway

EXPOSE 8080

CMD ["start-railway"]
