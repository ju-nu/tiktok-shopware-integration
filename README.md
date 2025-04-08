# TikTok Shopware Integration

A PHP 8.3+ application to process TikTok order CSV files and integrate them with Shopware 5.

## Requirements

- PHP 8.3+
- Composer
- Supervisor
- Apache/Nginx
- Shopware 5 API access

## Setup

1. Clone the repository:
   ```bash
   git clone https://github.com/ju-nu/tiktok-shopware-integration.git
   cd tiktok-shopware-integration
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Copy `.env.example` to `.env` and configure:
   ```bash
   cp .env.example .env
   nano .env
   ```

4. Set up web server (e.g., Apache):
   - Point the document root to `/public`.
   - Ensure `.htaccess` is enabled.

5. Configure Supervisor:
   - Copy `supervisor.conf` to `/etc/supervisor/conf.d/tiktok_csv_worker.conf`.
   - Update paths in the file.
   - Reload Supervisor:
     ```bash
     sudo supervisorctl reread
     sudo supervisorctl update
     sudo supervisorctl start tiktok_csv_worker
     ```

6. Ensure directories are writable:
   ```bash
   chmod -R 777 var src/Logs
   ```

## Usage

1. Visit the web interface (e.g., `http://localhost`).
2. Upload TikTok order CSV files.
3. The worker will process them asynchronously and create orders in Shopware.

## License

MIT
```

---

### Setup Instructions

1. **Clone and Install:**
   - Clone the repo to your server.
   - Run `composer install`.

2. **Configure Environment:**
   - Copy `.env.example` to `.env` and fill in your Shopware API credentials and queue directory.

3. **Set Up Web Server:**
   - Point your web server (e.g., Apache) to the `public` directory.
   - Ensure `.htaccess` works or configure Nginx equivalently.

4. **Start the Worker:**
   - Install Supervisor (`sudo apt install supervisor` on Ubuntu).
   - Configure `supervisor.conf` with correct paths and copy it to `/etc/supervisor/conf.d/`.
   - Run:
     ```bash
     sudo supervisorctl reread
     sudo supervisorctl update
     sudo supervisorctl start tiktok_csv_worker
     ```

5. **Test:**
   - Access the web interface, upload a CSV file like the one provided, and monitor `src/Logs/app.log` and `var/logs/worker.log`.

---

### Notes

- **Shopware API:** The code assumes a standard Shopware 5 API setup. Adjust `countryId`, `taxId`, and status IDs based on your Shopware configuration.
- **Error Handling:** The application logs errors and retries on rate limits with exponential backoff.
- **Scalability:** Add more workers in `supervisor.conf` (`numprocs`) for higher throughput.
- **Security:** Ensure `.env` is not publicly accessible and use HTTPS in production.
