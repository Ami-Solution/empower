wget -O ../shp/postcodes2011.zip "http://www.abs.gov.au/AUSSTATS/subscriber.nsf/log?openagent&1270055003_poa_2011_aust_shape.zip&1270.0.55.003&Data%20Cubes&71B4572D909B934ECA2578D40012FE0D&0&July%202011&22.07.2011&Previous"

cd ../shp/
unzip postcodes2011.zip
rm postcodes2011.zip

/usr/lib/postgresql/9.2/bin/shp2pgsql -I -s 4283 POA_2011_AUST.shp postcode_2011 ee | sudo -u postgres psql -d ee
rm POA_2011_AUST.*



