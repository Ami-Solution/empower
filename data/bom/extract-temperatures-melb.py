import os
import datetime
import csv
import urllib, urllib2
from zipfile import ZipFile
import json

# parameters

def is_number(s):
    try:
        float(s)
        return True
    except ValueError:
        return False

# Store the data from the CSV in a file
out_f = open("bom-tempmax-melb.csv","w")
out_f.write("bom_station_number,date,temp_max\n")

files = ['bom-temp-melb.zip']

for file in files:
	# Filename
	zf = ZipFile(file,'r')

	# Listing the resources in the zip file - there is only 1
	zfnl = zf.namelist()
	print 'Filename to extract from the archive: '+ zfnl[0]
	f = zf.open(zfnl[0])

	# It's a CSV file - we extract info in a format ready to be JSONified
	reader = csv.reader(f, delimiter=',', quoting=csv.QUOTE_NONE)
	zf.close()

	# Some info on the public prices CSV files
	# http://www.whit.com.au/blog/2012/07/all-you-need-to-analyse-the-electricity-market-pt-3/

	skip_first = True

	for row in reader:
		if skip_first:
			skip_first = False
			continue

		if len(row)>5 and int(row[2]) > 2009:
			# Selection of the right columns to consider
			f_bom_station = row[1]
			f_date = row[4]+"/"+row[3]+"/"+row[2]
			f_temp_max = row[5]

			# Writing a well-formed CSV line
			out_f.write("\""+f_bom_station+"\";\""+f_date+"\";"+f_temp_max+"\n")

	f.close()

out_f.close()

# The resulting CSV file can be loaded in PostgreSQL using:

# The destination structure:
# CREATE TABLE bom_temp_max
#(
# id serial NOT NULL,
# bom_station character varying,
# dat date,
# temp_max numeric(6,2),
# CONSTRAINT pk_bom_temp_max PRIMARY KEY (id)
#);

# The loading command:
# copy bom_temp_max (bom_station,dat,temp_max) 
# from '/var/lib/tomcat6/webapps/empower.me/data/bom/bom-tempmax-melb.csv'
# CSV HEADER DELIMITER ';'