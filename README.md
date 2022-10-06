Convert OpenProvider DNS records to Zone files
---

The script `get_zones.php` calls the OpenProvider API to receive a list of all domains and their DNS records.
These are written to `output/zones` as Bind zone files.

# Requiremets
- An [OpenProvider account](https://cp.openprovider.eu/dashboard/) with domains and DNS records in it.
- MacOS, FreeBSD or Linux
- [Composer](https://getcomposer.org/download/) installed
- Git
- PHP 8.0 or greater. Can be installed on MacOS using [HomeBrew](https://brew.sh/)

# Install
```
git clone git@github.com:Savvii/openprovider-csv2zone.git
cd openprovider-csv2zone
composer install
cp config.php.example config.php
```

Update `config.php`

Optional: Put a manual list of domains in `input/domainlist.txt`, each domain on a separate line.

# Execute
```
./get_zones.php
```
