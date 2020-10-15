#!/bin/sh
certbot certonly --agree-tos -n \
  -m brian@southroute.com \
  --dns-google \
  --dns-google-credentials /run/secrets/cloud_dns \
  -d quiplash.telegram.southroute.dev ; \
  sleep 8h