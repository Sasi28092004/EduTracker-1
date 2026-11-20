#!/bin/sh
# EduTracker container start: Node API on 127.0.0.1:3000, Apache/PHP on $PORT.

WEB_PORT="${PORT:-10000}"

# One shared key between PHP and Node. Generated if you didn't set API_KEY.
if [ -z "$API_KEY" ]; then
    API_KEY="$(node -e "console.log(require('crypto').randomBytes(32).toString('hex'))")"
    export API_KEY
    echo "[start] API_KEY not set - generated one for this run."
fi

# PHP -> Node inside the container; PDF links stay on the same site.
export API_BASE_URL="${API_BASE_URL:-http://127.0.0.1:3000/api}"
export FILE_VIEW_BASE_URL="${FILE_VIEW_BASE_URL:-/}"

# Apache listens on Render's port.
sed -i "s/^Listen .*/Listen ${WEB_PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${WEB_PORT}>/" /etc/apache2/sites-available/000-default.conf

# Node API, restarted automatically if it stops.
(
    cd /app/server || exit 1
    while true; do
        PORT=3000 node server.js
        echo "[start] API stopped - restarting in 3 seconds..."
        sleep 3
    done
) &

# Wait (max 60 s) until the API answers, so the first page load works.
i=0
until node -e "require('http').get('http://127.0.0.1:3000/api/health',r=>process.exit(r.statusCode===200?0:1)).on('error',()=>process.exit(1))" 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 60 ]; then
        echo "[start] API not ready after 60 s - starting the website anyway (check MONGODB_URI / Atlas IP access)."
        break
    fi
    sleep 1
done

echo "[start] Website on port ${WEB_PORT}, API on 127.0.0.1:3000"
exec apache2-foreground
