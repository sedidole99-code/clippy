FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        xclip \
        wl-clipboard \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY clippy.php .

EXPOSE 18080

ENTRYPOINT ["php", "clippy.php"]
