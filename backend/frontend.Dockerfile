FROM nginx:1.30.4-alpine

# Nur die freigegebenen HTML-Dateien werden ausgeliefert. Der Backend-
# Quellcode gelangt dadurch nicht in den öffentlichen Webserver-Container.
COPY index.html login.html admin.html kunde.html api-dokumentation.html angebote.html angebot.html vertrag.html konto-aktivieren.html konto.html passwort-vergessen.html passwort-zuruecksetzen.html status.html tickets.html dokumente.html racks.html rackbelegung.html netzwerk.html session-manager.js branding.js world-map.png /usr/share/nginx/html/
COPY backend/frontend.nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
