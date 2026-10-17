
FROM php:8.3-apache

# 安裝 PHP MySQL 擴充套件
RUN docker-php-ext-install pdo_mysql mysqli

# 啟用 Apache rewrite
RUN a2enmod rewrite

# 複製 PHP 專案到網站根目錄
COPY . /var/www/html/

# Railway 提供的 PORT 由 Apache 啟動時設定
RUN printf '#!/bin/sh\nset -e\nPORT="${PORT:-8080}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground\n' > /usr/local/bin/start-railway \
    && chmod +x /usr/local/bin/start-railway

CMD ["start-railway"]
