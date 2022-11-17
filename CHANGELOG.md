CHANGELOG for OpenProvider Zone Export
=================

## 1.x-dev
### Features:
* strict_types
* Output DNSSEC info JSON
* $config['skip_existing'] can be set to skip zone files which already exist
### Other Changes
* Moved GetAllTool + UpdateTool class to a separate file
* Added Savvii\OpenproviderZoneExport namespace with PSR-4 autoload

## 1.0.0
Initial Release
### Features:
* Feature: Retrieve zone files
* Feature: Retrieve custom nameservers
* Feature: Update DNS servers in all zone files
