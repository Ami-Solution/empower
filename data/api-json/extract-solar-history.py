import os, datetime
import urllib,urllib2
import json

# Date range between a start date and today
dt1 = datetime.datetime.strptime('2013-05-08', '%Y-%m-%d')
dt2 = datetime.datetime.today()

# Generating a list of dates within the range
delta = dt2 - dt1
nbDays = delta.days
# Note: we avoid today's date as it is an uncomplete file

for x in range(0, nbDays):
	dt = dt1 + datetime.timedelta(days=x)

	# Looping thru the list of dates
	url_json = "http://pv-map.apvi.org.au/data/"+dt.strftime('%Y-%m-%d')

	path = "data/"+dt.strftime('%Y-%m-%d')+".json"

	if os.path.isfile(path):
		file_size = os.stat(path).st_size
		print "File: "+path+" has size "+str(os.stat(path).st_size)
		if file_size == 1:
			print "Fetching: "+url_json
			try:
				fn, d = urllib.urlretrieve(url_json,"data/"+dt.strftime('%Y-%m-%d')+".json")
			except urllib.error.HTTPError as e:
				print(e.code)
				print(e.read()) 

# parameters
rootdir = 'data'

# Store the data from the CSV in a file
out_f = open("data/solar_performance_history.csv","w")
out_f.write("region,measure_date,measure_time,performance\n")

for subdir, dirs, files in os.walk(rootdir):
	files.sort()
	for file in files:
		# Filename
		print "Opening: " + subdir+'/'+file
		f = open(subdir+'/'+file,'r')

		# Open up as a json
		try:
			json_data = json.load(f)
		except:
			print "Not a real JSON file: " + subdir+'/'+file

		# Going thru the JSON to extract the right data
		for ts_slice in json_data["performance"]:
			s_ts = ts_slice["ts"]
			s_ts_fixeddate = datetime.datetime.strptime(s_ts, '%Y-%m-%dT%H:%M:%SZ') + datetime.timedelta(hours=10)

			s_ts_date = datetime.datetime.strftime(s_ts_fixeddate, '%Y-%m-%d')
			s_ts_time = datetime.datetime.strftime(s_ts_fixeddate, '%H:%M:%S')

			if datetime.datetime.strftime(s_ts_fixeddate, '%M') in ('00','30'):
				if ts_slice.has_key("nsw"):
					s_nsw = ts_slice["nsw"]
					out_f.write("\"NSW\";\""+s_ts_date+"\";\""+s_ts_time+"\";"+s_nsw+"\n")

				if ts_slice.has_key("qld"):
					s_qld = ts_slice["qld"]
					out_f.write("\"QLD\";\""+s_ts_date+"\";\""+s_ts_time+"\";"+s_qld+"\n")

				if ts_slice.has_key("sa"):
					s_sa = ts_slice["sa"]
					out_f.write("\"SA\";\""+s_ts_date+"\";\""+s_ts_time+"\";"+s_sa+"\n")

				if ts_slice.has_key("tas"):
					s_tas = ts_slice["tas"]
					out_f.write("\"TAS\";\""+s_ts_date+"\";\""+s_ts_time+"\";"+s_tas+"\n")

				if ts_slice.has_key("vic"):
					s_vic = ts_slice["vic"]
					out_f.write("\"VIC\";\""+s_ts_date+"\";\""+s_ts_time+"\";"+s_vic+"\n")

out_f.close()

# The resulting CSV file can be loaded in PostgreSQL using:

# The destination structure:
# CREATE TABLE solar_performance
# (
#  id serial NOT NULL,
#  region character varying(5),
#  measure_date date,
#  measure_time time without time zone,
#  performance numeric(9,2),
#  CONSTRAINT pk_solar_performance PRIMARY KEY (id)
# )

# The loading command:
# copy solar_performance (region,measure_date,measure_time,performance) 
# from '/var/lib/tomcat6/webapps/empower.me/data/api-json/data/solar_performance_history.csv'
# CSV HEADER DELIMITER ';' 