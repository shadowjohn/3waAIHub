FROM php:8.3-cli-alpine

WORKDIR /app
COPY router.php /app/router.php

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "/app/router.php"]
