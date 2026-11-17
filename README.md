# IdeaMart - E-Commerce Platform with AI Tools

A PHP and MySQL e-commerce web application with built-in store management, point-of-sale (POS) tools, and automated installation.

[![PHP](https://img.shields.io/badge/PHP-7.4%20%7C%208.x-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/Database-MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)

---

## Overview

**IdeaMart** provides an online retail storefront alongside an administrative management back-office. It includes product browsing, shopping cart workflows, checkout processing, order tracking, and a built-in Point of Sale (POS) screen for in-person orders.

---

## Core Capabilities

### Customer Front-End
- **Product Catalog**: Category browsing, product detail views, and search.
- **Cart & Checkout**: Multi-step checkout with delivery calculations and order review.
- **Order Tracking**: Order lookup by invoice number and phone.
- **Payment Options**: Support for mobile banking (bKash) and cash on delivery.

### Admin Dashboard (`/admin`)
- **Store Analytics**: Overview of orders, revenue, and customer registrations.
- **Catalog Management**: Add, edit, import products, categories, and banners.
- **POS Interface**: Quick counter sales and printable customer receipts.
- **Order Processing**: Status updates, shipment tracking, and invoice printing.
- **Automation & AI Tools**: Automated social post scheduling and AI helper settings.

---

## Requirements

- **PHP**: Version 7.4 or higher (8.0+ supported)
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Web Server**: Apache with `mod_rewrite` enabled
- **PHP Extensions**: `pdo_mysql`, `curl`, `mbstring`, `json`, `gd`

---

## Installation & Setup

### Local Setup (XAMPP / WampServer)
1. Clone or extract this repository into your local web root directory:
   ```bash
   git clone https://github.com/mujahidul80/ideamart-ecommerce-ai.git
   ```
2. Place the folder inside `htdocs` (e.g., `C:/xampp/htdocs/ideamart`).
3. Start Apache and MySQL services from the XAMPP Control Panel.
4. Open your web browser and navigate to the web installer:
   ```text
   http://localhost/ideamart/install.php
   ```
5. Follow the setup wizard to verify system requirements and configure your database.
6. Once installation completes, open the admin panel:
   ```text
   http://localhost/ideamart/admin/
   ```

### Live Server Deployment (cPanel / VPS)
1. Upload the files to your server's `public_html` or designated subdomain directory.
2. Ensure directory write permissions are enabled for `uploads/` (`chmod 755` or `chmod 775`).
3. Create a MySQL database and user in your hosting control panel.
4. Run `https://yourdomain.com/install.php` in your browser to finalize configuration.

---

## Project Structure

```text
ideamart-ecommerce-ai/
├── admin/               # Back-office dashboard, POS, catalog, and settings
├── api/                 # API endpoints and background handlers
├── uploads/             # Product and banner media storage
├── db.php               # Database configuration file (generated on install)
├── install.php          # Web-based initial setup wizard
├── quick_setup.php      # Fast configuration helper
├── index.php            # Storefront homepage
├── cart.php             # Shopping cart page
├── checkout.php         # Customer checkout flow
├── track-order.php      # Customer order tracking page
└── .htaccess            # URL routing and rewrites
```

---

## License

This project is released under the [MIT License](LICENSE).

---

## Author & Support

- **Developer**: Mujahidul Islam ([@mujahidul80](https://github.com/mujahidul80))
- **WhatsApp**: [+880 1571-432927](https://wa.me/8801571432927) (Click to chat)
- **Website / Blog**: [mujahidulib.blogspot.com](https://mujahidulib.blogspot.com)
- **Repository**: [mujahidul80/ideamart-ecommerce-ai](https://github.com/mujahidul80/ideamart-ecommerce-ai)
