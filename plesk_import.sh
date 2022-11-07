#!/usr/bin/env bash
set  +o xtrace  -o errexit  -o nounset  -o pipefail  +o history

# Files must be readable by user 'psaadm'
ZONEDIR=output/zones

printf  "\e[33m>>>> %s\e[0m\n"  "Making sure 'dns-transfer' extension is installed"
plesk  bin  extension  --install dns-transfer

while IFS=" ":  read -r DOMAIN <&3;  do
    DOMAIN_ID=$( plesk bin domain  --info "${DOMAIN}" | grep 'Domain ID:' | awk '{ print $3 }' )
    ZONEFILE="${ZONEDIR}/${DOMAIN}."
    if [ ! -f "${ZONEFILE}" ]; then
        printf  "\e[41mERROR: %s\e[0m\n"  "Zone file '${ZONEFILE}' not found."
        continue
    fi
    printf  "\e[33m>>>> %s\e[0m\n"  "Deleting DNS records for '${DOMAIN}'"
    plesk  bin dns  --del-all "${DOMAIN}"
    printf  "\e[33m>>>> %s\e[0m\n"  "Importing DNS records for '${DOMAIN}'"
    plesk  ext dns-transfer  import "${DOMAIN_ID}" "${ZONEFILE}" -y | grep  --invert-match 'Cannot delete the last NS DNS record for the domain.'
    plesk  ext dns-transfer  import "${DOMAIN_ID}" "${ZONEFILE}" -y
done 3<<< "$( plesk  bin domain  --list )"

printf  "\e[33m>>>> %s\e[0m\n"  "Done"