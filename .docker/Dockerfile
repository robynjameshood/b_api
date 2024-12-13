FROM teamawesomedev/backend:v_0.15

COPY . /var/www/html
COPY .docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

CMD printf "search ncigroup.local\nnameserver 127.0.0.11\nnameserver 10.0.0.221\nnameserver 10.0.0.213\noptions ndots:0" > /etc/resolv.conf && apachectl -D FOREGROUND && /bin/bash
