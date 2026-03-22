FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git curl zip unzip libpq-dev libzip-dev libpng-dev libonig-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip mbstring gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copy rest of app
COPY . .

# Init dummy git repo so Laravel packages don't fail git checks
RUN git init && git config user.email "deploy@render.com" && git config user.name "Render" \
    && git add -A && git commit -m "deploy"

# Storage permissions
RUN mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views \
    && chmod -R 775 storage bootstrap/cache

RUN php artisan config:clear || true

EXPOSE 8000

CMD php artisan migrate --force \
    && php artisan tinker --execute="if(DB::table('decks')->count()==0){Artisan::call('db:seed',['--class'=>'DemoSeeder','--force'=>true]);}" \
    && php artisan tinker --execute="if(DB::table('users')->where('is_admin',true)->count()==0 && env('ADMIN_EMAIL') && env('ADMIN_PASSWORD')){\App\Models\User::create(['name'=>'admin','email'=>env('ADMIN_EMAIL'),'password'=>bcrypt(env('ADMIN_PASSWORD')),'is_admin'=>true,'is_enabled'=>true,'email_verified_at'=>now()]);}" \
    && php artisan serve --host=0.0.0.0 --port=8000
