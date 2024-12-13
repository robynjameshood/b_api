#!/usr/bin/env bash

# Add our DNS servers to the list
# In reverse-preferred order, because prepend will add the last one specified to the front of the list
# Only add two, because a maximum of 3 servers will be used, and we still want to fail back to AWS's provided server

# LIVE AWS London Jig-DC-03 (New DC in AWS will have lower latency than on-prem)
echo 'prepend domain-name-servers 10.2.4.45;' >> /etc/dhcp/dhclient.conf

# LIVE AWS London NCI-DC-04 (AWS will have lowest latency & least likely to have connection issues)
echo 'prepend domain-name-servers 10.2.3.125;' >> /etc/dhcp/dhclient.conf


# At some point we might want to include our domain as a search domain, buuut I've not found any reason to yet
#echo 'append domain-search "ncigroup.local";' >> /etc/dhcp/dhclient.conf

# Restart the network service to apply our changes
service network restart