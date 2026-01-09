# Deployment Guide

This guide covers deploying the **Fashion Marketplace API** to [Render](https://render.com), using **TiDB** for the database and **Resend** for emails.

## Repository
[https://github.com/iAmTechified/fashion-marketplace](https://github.com/iAmTechified/fashion-marketplace)

## Prerequisites

- [Render Account](https://render.com)
- [TiDB Cloud Account](https://tidbcloud.com)
- [Resend Account](https://resend.com)

## 1. Database Setup (TiDB)

1.  Log in to TiDB Cloud and create a new cluster (Serverless is fine for starting).
2.  Navigate to **Connect** -> **Connect with Code** or **General**.
3.  Note down the following details:
    - **Host**
    - **Port** (usually 4000)
    - **Database Name**
    - **Username**
    - **Password**
4.  TiDB requires a secure connection. Grab the CA certificate path if running locally, or rely on the system CA in the container.
    - Note: The `render.yaml` and `config/database.php` are already configured to look for the system CA at `/etc/ssl/certs/ca-certificates.crt`.

## 2. Email Setup (Resend)

1.  Log in to [Resend](https://resend.com).
2.  Create an API Key.
3.  **No Custom Domain?**
    - You can use the "Testing" setup provided by default.
    - **Sender**: Emails must be sent from `onboarding@resend.dev` (this is already configured in `render.yaml`).
    - **Recipient**: during testing, you can **ONLY** send emails to the address you used to sign up for Resend. Emails to other addresses will be blocked until you verify a domain.

## 3. Render Deployment

The repository includes a `render.yaml` (Blueprint) file, which automates the setup.

### Option A: Deploy via Blueprint (Recommended)

1.  Go to the [Render Dashboard](https://dashboard.render.com).
2.  Click **New +** -> **Blueprint**.
3.  Connect your GitHub repository `iAmTechified/fashion-marketplace`.
4.  Render will read `render.yaml` and ask for the missing Environment Variables (marked as `sync: false` in the YAML).

### Option B: Manual Service Creation

If you prefer to set it up manually:
1.  Create a new **Web Service**.
2.  **Runtime**: Docker
3.  **Build Command**: `docker build -t fashion-marketplace .` (automatic with Docker runtime)
4.  **Start Command**: `./entrypoint.sh`

### Environment Variables

You MUST provide these variables in the Render Dashboard:

| Variable | Description |
| :--- | :--- |
| `APP_KEY` | Run `php artisan key:generate --show` locally to generate one. |
| `DB_HOST` | TiDB Host (e.g., `gateway01.us-west-2.prod.aws.tidbcloud.com`) |
| `DB_DATABASE` | TiDB Database Name |
| `DB_USERNAME` | TiDB Username |
| `DB_PASSWORD` | TiDB Password |
| `MAIL_PASSWORD` | Your Resend API Key |

*Note: The following are already set in `render.yaml` or `Dockerfile` but good to verify:*
- `DB_CONNECTION`: `mysql`
- `DB_PORT`: `4000`
- `MAIL_HOST`: `smtp.resend.com`
- `MAIL_PORT`: `465`
- `MAIL_ENCRYPTION`: `ssl`

## 4. Local Development

To work locally without breaking production settings:

1.  **Environment**: Copy `.env.example` to `.env`.
    ```bash
    cp .env.example .env
    ```
2.  **Database**:
    - You can use a local SQLite file for speed:
      ```ini
      DB_CONNECTION=sqlite
      # Comment out DB_HOST, etc.
      ```
    - OR connect to TiDB (remote):
      Fill in the `DB_*` variables in `.env` with your TiDB credentials.
3.  **Install Dependencies**:
    ```bash
    composer install
    npm install
    ```
4.  **Run**:
    ```bash
    # Start the Laravel server
    php artisan serve

    # (Optional) Watch frontend assets if you add views later
    npm run dev
    ```

## Troubleshooting

- **502 Bad Gateway**: Check the Render logs. It often means the app failed to start (e.g., database connection failed).
- **Migration Errors**: Ensure the database user has permission to create tables.
- **SSL Errors**: If TiDB complains about SSL, ensure `MYSQL_ATTR_SSL_CA` matches the path in the Docker container (`/etc/ssl/certs/ca-certificates.crt`).

