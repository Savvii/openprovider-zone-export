Convert OpenProvider DNS records to Zone files
---

The script `get_zones.php` calls the OpenProvider API to receive a list of all domains and their DNS records.
These are written to `output/zones` as Bind zone files.

# Requirements
- An [OpenProvider account](https://cp.openprovider.eu/dashboard/) with domains and DNS records in it.
- MacOS, FreeBSD or Linux
- [Composer 2.x](https://getcomposer.org/download/) installed
- Git
- PHP 8.0 or greater with the GMP and DOM extension enabled. Can be installed on MacOS using [HomeBrew](https://brew.sh/)

# Install
## Clone project & Composer Install
```
git clone git@github.com:Savvii/openprovider-zone-export.git
cd openprovider-zone-export
composer install
cp config.php.example config.php
```

## Update Configuration
Edit `config.php`
- Configure `op_username` with your OpenProvider username.
- Configure `op_password` with your OpenProvider password.

Optional: Put a manual list of domains in `input/domainlist.txt`, each domain on a separate line.

# Execute
## Get zones from OpenProvider API
```
./get_zones.php
```
The zone files will be written to `output/zones`.
Custom nameservers will be written to `output/custom_nameservers.json`

## Change nameservers in zone files
```
./update_zones.php
```
Zone files in `output/zones` will be updated.
Custom nameservers need to be specified in `config.php`.