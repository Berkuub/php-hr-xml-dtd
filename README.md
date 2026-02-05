# HR XML Export (PHP + MySQL)

## Description
This project contains a PHP script that connects to a MySQL database (HR schema),
extracts data from the `employees` table, and generates:

- XML file (`hr_export.xml`)
- DTD file (`hr_export.dtd`)

The XML document is validated against the generated DTD and includes a control section
with summary information.

## Files
- `export_hr.php` – PHP script for database connection and XML/DTD generation
- `hr_export.xml` – Generated XML document
- `hr_export.dtd` – DTD definition for XML validation

## Database
- Database name: `hr`
- Table: `employees`
- Exported rows: first 10 rows ordered by primary key

## Control Section
The XML document includes a `<control>` element containing:
- rowCount
- columnCount
- minId
- maxId
- checksum

## Technologies
- PHP 8
- MySQL (MariaDB)
- PDO
- DOMDocument

## Author
Berkan Taner 253765
