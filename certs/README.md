# Included Local HTTPS Certificates

This demo repo intentionally commits a self-signed certificate and key so the
HTTPS Vite repro works without a certificate generation step.

The certificate covers `localhost`, `127.0.0.1`, and `::1`. It is only for local
development in this throwaway performance repro.
