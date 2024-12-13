# HubSpot Sequence Enrollment Automation Tool

## Overview

This PHP-based tool automates the process of enrolling contacts from a HubSpot list into a specific sequence. It provides robust error handling, tracking, and reporting capabilities for bulk contact enrollments.

## Features

- Enroll contacts from a HubSpot list into a sequence
- Advanced error tracking and logging
- Sender email rotation
- Configurable enrollment processing
- Detailed enrollment statistics
- Optional retry mechanism for failed enrollments

## Prerequisites

- PHP 8.x
- Composer
- HubSpot account with API access
- HubSpot API key

## Installation

1. Clone the repository
2. Install dependencies:
```bash
composer install
```
Create a .env file with your HubSpot API key

   ``` env
   HUBSPOT_API_KEY='your_hubspot_api_key_here'
   ``` 
## Sender Emails
Configure sender emails in config/sender_emails.json:
```json
{
    "senders": [
        {
            "email": "sender@example.com",
            "userId": "user_id_string"
        }
    ]
}
```
## Usage

1. Run the script:
```bash
php enroll_sequence.php <list_id> <sequence_id>
```
2. Watch the script's output in real-time, including error tracking and statistics.

## Advanced Options

- `-ignore-errors`: Retry enrollment for contacts that previously failed
``` bash
php enroll_sequence.php -ignore-errors <list_id> <sequence_id>
```

## Logging

Error logs are stored in logs/enrollments_<list_id>_<sequence_id>.json

## Error Handling

- Automatically handles rate limits
- Rotates sender emails
- Provides detailed error tracking
- Skips already enrolled contacts by default

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

