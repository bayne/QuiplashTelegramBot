FROM nginx
COPY nginx.conf /etc/nginx/nginx.conf
COPY index.html /var/www/htdocs/index.html
COPY js /var/www/htdocs/js
CMD ["timeout", "9h", "nginx", "-g", "daemon off;"]