# GaoQing · AI Batch Design System for Fixed-Template Products

> Upload a fixed-structure mask, enter a simple theme, select a style, and generate batches of production-ready 2D artwork automatically.
>
> GaoQing is designed for stickers, phone cases, controller skins, laptop stickers, camera stickers, faceplates, light panels, and almost any product with a fixed printable template.

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![Storage](https://img.shields.io/badge/Storage-File%20Based-orange?style=flat-square)]()
[![Database](https://img.shields.io/badge/Database-None-lightgrey?style=flat-square)]()

---

## User Trial

C-end users can try the system here:

https://mots.detasche.cn/aidesign/

---

## Table of Contents

- [What Is GaoQing?](#what-is-gaoqing)
- [Who Is It For?](#who-is-it-for)
- [What Can It Generate?](#what-can-it-generate)
- [Key Features for Users](#key-features-for-users)
- [Why It Matters](#why-it-matters)
- [How It Works](#how-it-works)
- [Example Use Cases](#example-use-cases)
- [Admin and Production Features](#admin-and-production-features)
- [Technical Highlights](#technical-highlights)
- [If You Want to Deploy the System Yourself](#if-you-want-to-deploy-the-system-yourself)
- [Project Structure](#project-structure)
- [Configuration](#configuration)
- [FAQ](#faq)
- [Security Notes](#security-notes)
- [License](#license)

---

## What Is GaoQing?

GaoQing is a PHP-based AI batch design system built for fixed-template products.

Many physical products share a stable printable area or a fixed shape, such as game controller stickers, phone cases, laptop stickers, camera stickers, acrylic light panels, and custom faceplates. Designing these products one by one is repetitive, time-consuming, and expensive.

GaoQing turns this process into an automated AI workflow.

Users only need to:

1. Select or upload a product template mask.
2. Enter a theme or design idea.
3. Choose a visual style or write a custom style.
4. Submit the order.

The system will automatically let AI create design ideas, generate artwork, crop the result according to the mask, and package the final files for preview and download.

In simple words: **give GaoQing a template and a theme, and it can generate batches of usable product artwork automatically.**

---

## Who Is It For?

GaoQing is especially useful for:

- Individual creators who want to make custom products quickly
- Sticker and skin sellers
- Phone case design studios
- Game controller accessory sellers
- E-commerce shops that need many product designs
- Small teams that need high design output with low cost
- IP derivative product creators
- Gift, trend toy, and custom accessory businesses

It is designed for C-end users and small production teams who want to generate large numbers of design drafts without professional design experience.

---

## What Can It Generate?

GaoQing can be used for almost any product with a fixed printable area or fixed template, including:

- Game controller stickers
- Controller faceplates
- Phone case artwork
- Laptop stickers
- Camera body stickers
- Acrylic light panel designs
- Decorative product skins
- Custom product labels
- IP derivative stickers
- Trend toy labels
- Gift product artwork
- Other template-based creative products

As long as the product has a clear mask or fixed printable template, GaoQing can help batch-generate design drafts for it.

---

## Key Features for Users

### Batch AI Image Generation

GaoQing supports batch design generation for fixed-template products. Instead of making one design at a time, users can submit a theme and generate multiple design directions in one workflow.

### Theme-Based Creativity

Users only need to provide a simple theme, such as:

- Cyberpunk city
- Cute cat astronaut
- Dark fantasy dragon
- Pink anime girl style
- Summer beach party
- Retro racing poster

The AI will automatically create the visual idea, design direction, and image prompt.

### Automatic Template Cropping

After the AI image is generated, GaoQing automatically crops and masks the artwork according to the uploaded template. This keeps the product shape, holes, borders, and printable area aligned with the original template.

### Supports Custom Styles

Users can choose from built-in styles or write their own custom style descriptions. This makes it possible to generate artwork in different visual directions, such as cute, cyberpunk, luxury, minimal, anime, retro, futuristic, street art, and more.

### Low Token Consumption and Low Cost

The system is designed to keep prompt generation lightweight and efficient. It consumes very few tokens compared with heavy multi-agent design workflows, making the overall generation cost extremely low.

### Production-Friendly Output

Generated files can be previewed, downloaded individually, or packaged as a complete order. This makes it easier to move from AI design to actual production.

### Private Template Management

Each user can manage their own templates and masks. User assets, orders, and output files are isolated from other users.

### Visual Progress Tracking

Users can see order status, queue position, current step, running time, and estimated progress in real time.

---

## Why It Matters

Traditional product design often requires repeated manual work, especially when creating many designs for similar products. For example, a seller may need hundreds of different visual themes for the same phone case template or controller sticker template.

GaoQing greatly reduces this repetitive work.

With this system, users do not need to manually draw every design. They only need to provide a theme, select a style, and let the system handle creativity, generation, cropping, and packaging.

This means:

- Faster design output
- Lower design cost
- Higher production efficiency
- More product variations
- Easier testing of new themes
- Better scalability for small studios and e-commerce sellers

For fixed-template products, GaoQing can significantly free up productivity and turn batch design into a repeatable automated workflow.

---

## How It Works

```text
┌────────────────────┐
│ Select / Upload Mask│
└─────────┬──────────┘
          │
          ▼
┌────────────────────┐
│ Enter Theme + Style │
└─────────┬──────────┘
          │
          ▼
┌────────────────────┐
│ Submit Design Order │
└─────────┬──────────┘
          │
          ▼
┌────────────────────────────┐
│ AI Creates Design Concepts  │
└─────────┬──────────────────┘
          │
          ▼
┌────────────────────────────┐
│ AI Generates Product Artwork │
└─────────┬──────────────────┘
          │
          ▼
┌────────────────────────────┐
│ System Crops by Mask        │
└─────────┬──────────────────┘
          │
          ▼
┌────────────────────────────┐
│ Preview / Download / Package│
└────────────────────────────┘
```

A typical order follows this process:

1. The user selects a product template.
2. The user enters a theme or design idea.
3. The user selects a built-in style or custom style.
4. The system calls an AI model to create a design prompt.
5. The image generation model creates the artwork.
6. The system applies the mask and crops the image automatically.
7. The user previews and downloads the final result.

---

## Example Use Cases

### Controller Sticker Batch Design

A controller accessory seller can upload a controller sticker mask, enter themes such as anime, cyberpunk, racing, dragon, cute pets, or futuristic city, and generate many design drafts for the same controller model.

### Phone Case Artwork

A phone case shop can prepare templates for different phone models and quickly generate large batches of artwork for different styles, trends, and customer preferences.

### Laptop and Camera Stickers

For laptop stickers or camera body stickers, users can upload fixed sticker templates and generate themed artwork while keeping the cut area and layout consistent.

### Light Panels and Faceplates

For acrylic light panels, custom faceplates, or decorative panels, GaoQing can generate visual artwork and automatically fit it into the target shape.

---

## Admin and Production Features

GaoQing also includes backend features for system operators:

- User management
- Order management
- Template and mask management
- Credit and billing management
- Recharge code management
- API configuration
- Brand information configuration
- Download and package management
- Error tracking and order status monitoring
- Admin password management

The backend makes it possible to operate the system as a small commercial service or internal production tool.

---

## Technical Highlights

- Pure PHP web application
- No database required
- File-based storage using JSON, PNG, and ZIP files
- Mask-based image cropping with GD
- File locking with `flock` for safer concurrent writes
- Queue-based order processing
- Step-level retry mechanism
- Order packaging into ZIP files
- CSRF protection
- Password hashing with bcrypt
- SameSite Cookie support
- Runtime data isolation by user

The project stores all runtime data in files, which makes it easy to deploy and test without MySQL, Redis, or other external database services.

---

## If You Want to Deploy the System Yourself

If you want to deploy the system yourself, please follow the process below:

### 1. Prepare the Server Environment

Requirements:

- PHP 8.1 or higher
- A web server such as Apache or Nginx + PHP-FPM
- Enabled PHP extensions:
  - `gd`
  - `curl`
  - `json`
  - `fileinfo`
  - `mbstring`
  - `zip`

Linux is recommended for production deployment.

### 2. Upload the Project Files

Upload the project files to your server:

```bash
git clone https://github.com/yourname/gaoqing.git
cd gaoqing
```

Or upload the files manually through your hosting panel.

### 3. Set Runtime Permissions

The system needs write permission to create runtime data:

```bash
chmod -R 755 .
mkdir -p _airate_runtime
chown -R www-data:www-data _airate_runtime
```

Adjust the user and group according to your server environment.

### 4. Point the Web Server to the Project

Set the project directory as your website root.

For Apache, point `DocumentRoot` to the project directory.

For Nginx, configure PHP-FPM and make sure `index.php` and `admin.php` can be executed.

### 5. Open the Admin Panel

Visit:

```text
https://your-domain.com/admin.php
```

Default admin account:

```text
Username: admin
Password: admin123
```

Change the default password immediately after your first login.

### 6. Configure API Keys

In the admin panel, open **System Configuration** and fill in:

- Brand name
- Company information
- Contact email
- GPT API URL
- GPT API Key
- GPT model name
- Image generation API URL
- Image generation API Key
- Image generation model name
- Credit and billing settings
- SMS settings if needed

### 7. Prepare Product Templates and Masks

Upload or prepare your product masks. A typical mask uses:

- White area = printable / design area
- Black area = protected / transparent / non-design area

The system will use this mask to crop and shape the final artwork.

### 8. Test the Full Workflow

Before public release, test:

- User registration and login
- Mask upload
- Theme input
- Style selection
- AI prompt generation
- Image generation
- Automatic cropping
- Order queue
- Credit deduction and refund
- File preview and download
- ZIP package generation

### 9. Customize for Your Business

You can modify:

- Product categories
- Default style library
- Frontend text
- Pricing logic
- Credit rules
- Template management logic
- Download package format
- Admin features

---

## Project Structure

```text
gaoqing/
├── index.php           # User-side entry: homepage, workspace, and APIs
├── admin.php           # Admin panel: users, orders, masks, credits, settings
├── config.php          # Configuration loader
├── README.md           # Project documentation
├── INSTALL.md          # Deployment guide
├── CONFIG.md           # Configuration details
├── LICENSE             # MIT License
├── .gitignore          # Excludes runtime data
└── _airate_runtime/    # Runtime data, automatically created
    ├── system/
    │   ├── app_config.json
    │   ├── indices.json
    │   ├── system.lock
    │   ├── queue.lock
    │   ├── cleanup_meta.json
    │   └── dev_sms.log
    ├── users/<USR_xxx>.json
    ├── masks/<USR_xxx>/<MASK_xxx>.{json,png}
    ├── orders/<ORDER_xxx>/
    │   ├── meta.json
    │   ├── order_mask.png
    │   ├── set1_raw.png
    │   ├── set1_masked.png
    │   ├── set1_idea.json
    │   ├── set1_idea.txt
    │   └── design_package.zip
    └── cards/card_keys.json
```

---

## Configuration

Most configuration can be edited in the admin panel.

| Module | Key Fields | Required |
| --- | --- | --- |
| Brand | App name, company, email, slogan | Recommended |
| Business | Free credits, cost per image, max concurrency | Has default values |
| GPT | API URL, API Key, model name | Required |
| Image Generation | Submit URL, result URL, API Key, model name | Required |
| SMS | Enable switch and SMS provider fields | Optional |
| Recharge Codes | Purchase link and recharge settings | Optional |
| Admin | Username and password | Must be changed after deployment |

For full details, see `CONFIG.md`.

---

## FAQ

<details>
<summary><b>Can GaoQing be used without SMS?</b></summary>

Yes. If SMS is disabled, the system can enter development mode and display verification codes directly. This is only recommended for testing or small private use. For public operation, use a real SMS service.
</details>

<details>
<summary><b>Can I use another GPT model?</b></summary>

Yes. If the API is compatible with the OpenAI Chat Completions format, you can change the GPT API URL, API key, and model name in the admin configuration. If the API format is completely different, you need to modify the GPT request function in the code.
</details>

<details>
<summary><b>Can I use another image generation model?</b></summary>

Yes, but the integration may need code changes. The system expects an image generation workflow that submits a task and retrieves the result. If your provider uses a different protocol, modify the image submission and polling functions.
</details>

<details>
<summary><b>Does the system need MySQL?</b></summary>

No. GaoQing uses file-based storage. User data, masks, orders, configuration, and recharge codes are stored as JSON, PNG, and ZIP files.
</details>

<details>
<summary><b>Will file-based storage cause concurrency issues?</b></summary>

The system uses file locks to reduce concurrency risks. For small and medium-scale use, file-based storage is simple and practical. For high-traffic commercial deployment, you may consider migrating to SQLite, MySQL, Redis, or a more robust queue system.
</details>

<details>
<summary><b>How are old orders cleaned?</b></summary>

The system can automatically clean expired order files based on retention settings. Template masks are not automatically deleted unless users or admins remove them.
</details>

---

## Security Notes

Please read this section carefully before deployment.

1. Do not commit `_airate_runtime/` to Git.
2. Change the default admin password immediately.
3. Block public web access to `_airate_runtime/`.
4. Use HTTPS in production.
5. Disable detailed error display in production.
6. Keep API keys in configuration files or environment variables, not in public code.
7. Set usage limits on your AI API provider account if possible.
8. Monitor abnormal user activity and credit consumption.

---

## License

[MIT License](LICENSE)

Third-party API keys and service accounts are not included. Deployers must apply for and configure their own AI, image generation, SMS, and related service credentials.

If this project helps you, feel free to Star the repository or submit improvements.
