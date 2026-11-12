FROM dunglas/frankenphp:1-php8.3
# exif: ImageCompressor memutar foto HP sesuai arah EXIF sebelum encode ulang —
# tanpa ini foto potret tersimpan miring. gd sudah ada sejak dompdf.
RUN install-php-extensions pdo_pgsql gd zip intl exif
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Default php.ini caps (2M/8M) are below UploadDocumentRequest's max:10240 (10MB),
# so valid 10MB phone photos were rejected before reaching Laravel validation.
RUN { \
    echo 'upload_max_filesize=12M'; \
    echo 'post_max_size=13M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini
WORKDIR /app
