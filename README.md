# MyInvoice Sync

**Contributors:** tikusL4ju   
**Tags:** lhdn, myinvoice, myinvois, einvoice, woocommerce  
**Requires at least:** 5.0  
**Tested up to:** 6.9  
**Stable tag:** 2.0.9  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Version:** 2.0.9  
**Repository:** [GitHub](https://github.com/tikusl4ju/myinvoice-sync)  

== Description ==

Automated invoice submission to LHDN (Lembaga Hasil Dalam Negeri) MyInvois system for Malaysian businesses.

== Short Description ==
Automated invoice submission to LHDN MyInvois system for Malaysian businesses.

## Overview

The MyInvoice Sync plugin automatically generates and submits invoices to the LHDN MyInvois portal in compliance with Malaysian tax regulations. It integrates seamlessly with WooCommerce to automate invoice submission.

**Payment Gateway Compatibility**: The plugin operates independently without requiring middleware and is compatible with all WooCommerce payment gateways, ensuring flexibility for your payment processing setup. The communication is directly to LHDN API.

## Features

### 🚀 Core Functionality

- **Automatic Invoice Submission**: Automatically generates and submits invoices to LHDN MyInvois when WooCommerce orders are completed
- **WooCommerce Integration**: Full integration with WooCommerce for seamless order-to-invoice workflow
- **UBL (Universal Business Language) Support**: Generates compliant UBL 1.0/1.1 formatted invoices
- **Digital Signatures**: Supports XMLDSig-style digital signatures for UBL 1.1 invoices
- **Environment Toggle**: Switch between Sandbox (Pre-Production) and Production environments
- **Flexible Billing Circle**: Configure when invoices are submitted (immediately or after a delay)

### 📋 Configuration Options

- **Billing Circle**: Choose when invoices are submitted:
  - On Completed Order (immediate submission)
  - On Processed Order (submit when order status is "processing")
  - After 1-7 Days (delayed submission with automatic cron processing)
- **Tax Category ID**: Select appropriate tax category (Standard Rated, Zero Rated, Exempt, etc.)
- **Industry Classification Code (MSIC)**: Select your business MSIC code from a comprehensive list
- **Seller Information**: Configure seller details (TIN, SST Number, TTX Number, address, etc.)
- **API Credentials**: Manage OAuth client credentials for API authentication

### 👤 User Profile Integration 

- **TIN Validation**: Users can add and validate their Tax Identification Number (TIN)
- **ID Type Support**: Supports NRIC, Passport, and other ID types
- **Frontend Integration**: TIN fields available in WooCommerce My Account and checkout pages
- **Validation Status**: Visual indicators showing TIN validation status

### 🔄 Automated Processing

- **Cron Jobs**: Automated background processing for:
  - Syncing submitted invoice statuses
  - Retrying failed submissions (with exponential backoff)
  - Processing delayed invoice submissions
- **Queue Management**: Automatic retry mechanism for failed submissions
- **Status Synchronization**: Regular sync of invoice statuses from LHDN portal

### 📊 Admin Dashboard

- **Invoice Management**: View all submitted invoices with status, UUID, and links
- **Settings Page**: Comprehensive configuration interface
- **Debug Logging**: Enable detailed logging for troubleshooting
- **Cron Status**: Monitor cron job execution times
- **Order Integration**: View LHDN submission status directly in WooCommerce orders list

## Installation

1. Upload the plugin files to `/wp-content/plugins/myinvoice-sync/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **MyInvoice Sync > Settings** to configure the plugin
4. Ensure you have your LHDN API credentials and PEM certificate file ready

### Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher (for WooCommerce integration)
- PHP 7.4 or higher
- MySQL 5.6 or higher
- LHDN MyInvois API credentials
- PEM certificate file (for UBL 1.1 digital signatures)


## Configuration

### Initial Setup

1. **Environment Selection**
   - Choose between **Sandbox** (for testing) or **Production** (for live submissions)
   - API and Portal URLs update automatically based on selection

2. **API Credentials**
   - Enter your **Client ID**
   - Enter **Client Secret 1** and **Client Secret 2**
   - These are provided by LHDN when you register for MyInvois API access

3. **Seller Information**
   - **Seller TIN**: Your business Tax Identification Number
   - **Seller ID Type**: Business Registration Number (BRN), NRIC, etc.
   - **Seller ID Value**: Your registration number
   - **Seller SST Number**: Your SST registration number (or "NA" if not applicable)
   - **Seller TTX Number**: Your TTX number (or "NA" if not applicable)
   - **Seller Name**: Registered business name
   - **Seller Contact**: Email, phone, and address details
   - **Seller Country**: Fixed to "MYS" (Malaysia)

4. **Tax Configuration**
   - **Tax Category ID**: Select from available tax categories
     - Service Tax
     - Not Applicable
     - Exempt (E)
   - **Industry Classification Code (MSIC)**: Select your business activity code

5. **Billing Circle**
   - **On Completed Order**: Submit immediately when order status changes to "completed"
   - **On Processed Order**: Submit when order status changes to "processing"
   - **After X Days**: Queue invoice for submission after specified number of days (1-7 days)

### PEM Certificate Setup

For UBL 1.1 invoices with digital signatures:
1. Navigate to **MyInvoice Sync > Settings** in WordPress admin
2. Scroll down to the **PEM Certificate Management** section
3. Click **Choose File** and select your PEM certificate file (`.pem` format only)
4. Click **Upload PEM Certificate** button
5. The plugin will automatically parse and extract certificate details (organization, serial number, validity dates, etc.)
6. Once uploaded, you can select UBL 1.1 in the settings above
7. Certificate details will be displayed, including validity dates and upload timestamp
8. You can reset the certificate at any time using the **Reset Certificate** button

**Note**: The certificate must be in valid PEM format and will be stored securely in the database. Only one active certificate is allowed at a time.

## Usage

### Automatic Submission

Once configured, the plugin automatically:
1. Detects when WooCommerce orders reach the configured status
2. Generates UBL-compliant invoice documents
3. Submits invoices to LHDN MyInvois API
4. Tracks submission status and updates order metadata

### Manual Submission

From the WooCommerce Orders list:
1. Navigate to **WooCommerce > Orders**
2. Find orders with "Not Submitted" status in the LHDN MyInvois column
3. Click the **Process** button to manually submit the invoice
4. Note: Process button only appears for "Completed", "Processing" or custum status orders.

### Viewing Invoices

1. Navigate to **MyInvoice Sync > Invoices** in WordPress admin
2. View all submitted invoices with:
   - Invoice number
   - Submission status
   - UUID and Long ID
   - Direct links to LHDN portal
   - Item classification type

### Status Indicators

- **Not Submitted**: Invoice has not been submitted yet
- **Processing**: Invoice is being processed by LHDN
- **Submitted**: Invoice successfully submitted (click to view on LHDN portal)
- **Cancelled**: Invoice has been cancelled
- **Queueing**: Invoice is in retry queue
- **Failed**: Submission failed (will be retried automatically)

## WooCommerce Integration

### Order Status Integration

The plugin adds a "LHDN MyInvois" column to the WooCommerce orders list showing:
- Submission status
- Direct links to view invoices on LHDN portal
- Manual submission button for unsubmitted orders

### Order Processing

- **Consolidated Invoices**: For Malaysian customers without validated TIN
- **E-Commerce Invoices**: For customers with validated TIN or foreign customers
- **Automatic Detection**: Plugin automatically determines invoice type based on customer data

## Billing Circle Options

### On Completed Order
- Invoices are submitted immediately when order status changes to "completed"
- Best for: Real-time invoice generation and immediate compliance

### On Processed Order
- Invoices are submitted when order status changes to "processing"
- Best for: Early invoice generation before order completion

### After X Days (1-7 Days)
- Orders are queued when completed
- Invoices are automatically submitted after the specified number of days
- Processed by automated cron jobs
- Best for: Businesses that need delayed invoice submission

**How Delayed Submission Works:**
1. When order completes, a queue record is created in the database
2. Queue status includes the number of days and date (format: `q1dmy`, `q2dmy`, etc.)
3. Cron job runs every 10 minutes to check for ready invoices
4. Invoices are automatically submitted when the delay period expires

## User Profile TIN Validation

### For Customers

Customers can add their TIN information in:
- **WordPress Admin**: User profile page (for admin users)
- **WooCommerce My Account**: Account details page
- **Checkout Page**: TIN status badge displayed

### TIN Validation Process

1. Customer enters TIN, ID Type, and ID Value
2. Plugin validates TIN with LHDN API
3. Validation status stored in user meta
4. Validated customers receive E-Commerce invoices instead of Consolidated invoices

### Supported ID Types

- **NRIC**: National Registration Identity Card (Malaysian)
- **Passport**: For foreign customers
- **BRN**: Business Registration Number
- **Other**: Custom ID types as supported by LHDN

## Cron Jobs

The plugin uses WordPress cron for automated tasks:

### Sync Submitted Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Sync status of submitted invoices from LHDN portal
- **Processes**: Up to 5 invoices per run

### Retry Queue Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Retry failed submissions (HTTP 429 rate limit errors)
- **Retry Logic**: Exponential backoff (up to 3 retries)
- **Processes**: Up to 5 invoices per run

### Process Delayed Invoices
- **Frequency**: Every 10 minutes
- **Purpose**: Process invoices queued for delayed submission
- **Processes**: Up to 20 invoices per run
- **Logic**: Checks queue dates and submits when delay period expires

### Monitoring Cron Status

View last execution times in **MyInvoice Sync > Settings**:
- Last Sync Cron
- Last Retry Cron
- Last Delayed Invoice Cron

## API Integration

### OAuth Authentication

The plugin handles OAuth token management automatically:
- Tokens are cached in the database
- Automatic token refresh when expired
- Environment-specific token storage (sandbox vs production)

### API Endpoints Used

- `/connect/token` - OAuth token endpoint
- `/api/v1.0/documentsubmissions/` - Submit invoices
- `/api/v1.0/documents/` - Get document details
- `/api/v1.0/documents/state/` - Cancel documents
- `/api/v1.0/taxpayer/validate/` - Validate TIN

### Error Handling

- **429 Rate Limiting**: Automatic retry with exponential backoff
- **Token Expiry**: Automatic token refresh
- **Network Errors**: Logged for debugging
- **Validation Errors**: Displayed to users

## Database Structure

The plugin creates the following database tables:

### `wp_lhdn_myinvoice`
Stores all invoice submissions with:
- Invoice number, UUID, Long ID
- UBL payload and document hash
- Submission status and HTTP response codes
- Queue status and dates (for delayed submissions)
- Retry counts and timestamps

### `wp_lhdn_tokens`
Stores OAuth access tokens:
- Encrypted access tokens
- Expiration timestamps
- Environment-specific storage

### `wp_lhdn_settings`
Stores plugin configuration:
- All settings from admin panel
- Serialized values for complex data

## Troubleshooting

### Invoices Not Submitting

1. **Check API Credentials**: Verify Client ID and Secrets are correct
2. **Check Environment**: Ensure correct environment (Sandbox/Production) is selected
3. **Check Order Status**: Verify order status matches billing circle setting
4. **Check Debug Logs**: Enable debug logging in settings to see detailed error messages
5. **Check Cron Jobs**: Verify WordPress cron is running (use WP-Cron or real cron)

### Common Issues

**Issue**: "Order already submitted" but shows "Not Submitted"
- **Solution**: Check if invoice exists in database with different status

**Issue**: Cron jobs not running
- **Solution**: Ensure WordPress cron is enabled, or set up real cron via WP-CLI

**Issue**: TIN validation failing
- **Solution**: Verify TIN format and ensure customer details match LHDN records

**Issue**: Digital signature errors (UBL 1.1)
- **Solution**: Verify PEM certificate is uploaded through **MyInvoice Sync > Settings > PEM Certificate Management**. Check that the certificate is valid, not expired, and in correct PEM format. You can view certificate details (validity dates, organization) in the settings page.

### Debug Mode

Enable debug logging in **Settings > Enable Debug Logging** to see:
- API requests and responses
- Invoice generation details
- Cron job execution logs
- Error messages and stack traces

View logs via AJAX endpoint or check WordPress debug log.

## Security

- All API credentials stored securely in database
- OAuth tokens encrypted
- Input sanitization on all user inputs
- Nonce verification on admin actions
- Capability checks for admin functions


## Snippets

### Certificate Management

#### Convert P12 to PEM Format

Convert your LHDN certificate from P12 format to PEM format:

```bash
OPENSSL_CONF=/etc/ssl/openssl.cnf openssl pkcs12 -legacy -in key.p12 -out lhdn.pem -nodes
```

**Note**: The `-legacy` flag is required for older P12 files. Remove it if you encounter compatibility issues.

#### Verify PEM Certificate

Check if your converted PEM certificate is valid:

```bash
# Check RSA key validity
openssl rsa -in lhdn.pem -check

# View certificate subject details
openssl x509 -in lhdn.pem -noout -subject

# View full certificate information
openssl x509 -in lhdn.pem -noout -text
```

#### View Certificate Validity Dates

```bash
# Check certificate expiration
openssl x509 -in lhdn.pem -noout -dates

# Check certificate validity period
openssl x509 -in lhdn.pem -noout -startdate -enddate
```

## Credits
Developed for Malaysian businesses requiring LHDN MyInvois compliance.

---

**Note**: This plugin requires valid LHDN MyInvois API credentials. Contact LHDN to obtain API access for your business.

## Support

No official support is provided. The plugin is free to use, but commercialization or redistribution for commercial purposes is not permitted. 

tikusL4ju@gmail.com