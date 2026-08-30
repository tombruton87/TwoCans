FROM nginx:1.27-alpine

# openssl generates the default self-signed certificate so https works the
# moment the box starts; certbot replaces it with a Let's Encrypt certificate
# when the household asks for one from the web interface. The DNS-01 plugin
# proves ownership through a Cloudflare TXT record, so no inbound port 80 is
# needed — it works behind CGNAT and double NAT too.
RUN apk add --no-cache openssl certbot certbot-dns-cloudflare

COPY default.conf /etc/nginx/conf.d/default.conf
COPY entrypoint.sh /usr/local/bin/nginx-entrypoint.sh
RUN chmod +x /usr/local/bin/nginx-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/nginx-entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
