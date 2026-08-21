# MedCMMS

A Computerized Maintenance Management System based on GLPI.

MedCMMS provides comprehensive maintenance management capabilities including asset tracking, work order management, preventive maintenance scheduling, and inventory management.

## Features

* **Asset Management**: Track and manage all your physical assets, equipment, and infrastructure
* **Work Order Management**: Create, assign, track, and close work orders efficiently
* **Preventive Maintenance**: Schedule and automate preventive maintenance tasks
* **Inventory Management**: Keep track of spare parts, consumables, and supplies
* **Reporting & Analytics**: Generate detailed reports on maintenance activities and asset performance
* **Contract Management**: Manage service contracts, warranties, and SLAs
* **Financial Management**: Track costs, depreciation, and budgets for maintenance operations
* **Knowledge Base**: Build and maintain a library of maintenance procedures and solutions

## Prerequisites

* A web server (Apache, Nginx, IIS, etc.)
* MariaDB >= 10.6 or MySQL >= 8.0
* PHP >= 8.2
* Mandatory PHP extensions:
    - dom, fileinfo, filter, libxml, simplexml, xmlreader, xmlwriter
    - bcmath, curl, gd, intl, mbstring, mysqli, openssl, zlib
* Supported browsers: Edge, Firefox, Chrome

## Installation

1. Clone this repository
2. Configure your web server to point to the `/public` directory
3. Follow the installation wizard at `/public/install/install.php`

## License

GNU GENERAL PUBLIC LICENSE Version 3 - see [LICENSE](LICENSE) for details.
