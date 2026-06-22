# Imagem base PHP 8.2 CLI (o bot é um processo de linha de comando, nao web)
FROM php:8.2-cli

# Dependencias de sistema + extensoes PHP que o discord-php e o PDO MySQL precisam
RUN apt-get update && apt-get install -y \
        libzip-dev \
        zlib1g-dev \
        unzip \
        git \
    && docker-php-ext-install pdo_mysql sockets zip \
    && rm -rf /var/lib/apt/lists/*

# Composer (copiado da imagem oficial)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Instala dependencias primeiro (aproveita cache do Docker)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copia o resto do codigo
COPY . .

# Comando padrao: roda o bot. (As cron jobs sobrescrevem isso com seu proprio startCommand.)
CMD ["php", "bot.php"]
