#!/bin/bash
# Copyright 2014 CloudHarmony Inc.
# 
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
# 
#     http://www.apache.org/licenses/LICENSE-2.0
# 
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


if [ "$1" == "-h" ] || [ "$1" == "--help" ] ; then
  cat << EOF
Usage: run.sh [options]

This repository provides an execution wrapper for the Iperf network benchmark
utilities.


RUNTIME PARAMETERS
The following runtime parameters and environment metadata may be specified 
(using run.sh arguments):

--collectd_rrd              If set, collectd rrd stats will be captured from 
                            --collectd_rrd_dir. To do so, when testing starts,
                            existing directories in --collectd_rrd_dir will 
                            be renamed to .bak, and upon test completion 
                            any directories not ending in .bak will be zipped
                            and saved along with other test artifacts (as 
                            collectd-rrd.zip). User MUST have sudo privileges
                            to use this option
                            
--collectd_rrd_dir          Location where collectd rrd files are stored - 
                            default is /var/lib/collectd/rrd

--font_size                 The base font size pt to use in reports and graphs. 
                            All text will be relative to this size (i.e. 
                            smaller, larger). Default is 9. Graphs use this 
                            value + 4 (i.e. default 13). Open Sans is included
                            with this software. To change this, simply replace
                            the reports/font.ttf file with your desired font
                            
--iperf_bandwidth           Set target bandwidth to n bits/sec (default 1 Mb/s 
                            for UDP, unlimited for TCP). If there are multiple 
                            streams (--iperf_parallel), the bandwidth limit is 
                            applied separately to each stream. You can also add 
                            a / and a number to the bandwidth specifier. This 
                            is called "burst mode". It will send the given 
                            number of packets without pausing, even if that 
                            temporarily exceeds the specified bandwidth limit. 
                            Setting the target bandwidth to 0 will disable 
                            bandwidth limits (particularly useful for UDP 
                            tests)

--iperf_interval            The frequency (seconds) to take bandwidth 
                            measurements - default is 1
                            
--iperf_len                 The length of buffers to read or write. Iperf works 
                            by writing an array of iperf_len bytes a number of 
                            times. Default is 8 KB for TCP, 1470 bytes for UDP.
                            See also the --iperf_num and --iperf_time options.
                            Value may be a numeric value (bytes), or numeric 
                            value followed by K (kilobytes) or M (megabytes)
                            
--iperf_mss                 Attempt to set the TCP maximum segment size (MSS) 
                            via the TCP_MAXSEG option. The MSS is usually the 
                            MTU - 40 bytes for the TCP/IP header. For ethernet, 
                            the MSS is 1460 bytes (1500 byte MTU)
                            
--iperf_nodelay             Set the TCP no delay option, disabling Nagles 
                            algorithm. Normally this is only disabled for 
                            interactive applications like telnet
                            
--iperf_num                 The number of buffers to transmit. Normally, Iperf 
                            sends for 10 seconds. The --iperf_num option 
                            overrides this and sends an array of --iperf_len 
                            bytes num times, no matter how long that takes. See 
                            also the --iperf_len and --iperf_time options
                            
--iperf_parallel            The number of simultaneous connections to make to 
                            the server. Default is 1. Requires thread support 
                            on both the client and server. May contain {cpus} 
                            which will be automatically replaced with the 
                            number of CPU cores. May also contain an equation 
                            which will be automatically evaluated (e.g. 
                            --iperf_parallel "{cpus}*2")

--iperf_server              IP address or hostname for the Iperf server(s) to 
                            test. If a server uses a non-default port (5001 for 
                            Iperf v1-2 and 5201 for Iperf v3), the port may be 
                            appended preceded by a colon 
                            (e.g. 192.168.0.233:5999). This parameter may be 
                            repeated to test multiple servers
                            
--iperf_server_instance_id  The compute instance type identifier for the 
                            compute service corresponding to --iperf_server. If 
                            --iperf_server is repeated, this parameter may also 
                            be repeated (in the same order), or designated only 
                            once (same for all --iperf_server). If not set 
                            --meta_instance_id will be assumed

--iperf_server_os           The operating system of the server corresponding to 
                            --iperf_server. If --iperf_server is repeated, this 
                            parameter may also be repeated (in the same order), 
                            or designated only once (same for all 
                            --iperf_server). If not set --meta_os will be assumed

--iperf_server_provider     The name of the cloud provider corresponding to 
                            --iperf_server. If --iperf_server is repeated, this 
                            parameter may also be repeated (in the same order), 
                            or designated only once (same for all 
                            --iperf_server). If not set --meta_provider will be 
                            assumed

--iperf_server_provider_id  The id of the cloud provider corresponding to 
                            --iperf_server. If --iperf_server is repeated, this 
                            parameter may also be repeated (in the same order), 
                            or designated only once (same for all 
                            --iperf_server). If not set --meta_provider_id will 
                            be assumed

--iperf_server_region       The region identifier for the compute service 
                            corresponding to --iperf_server. If --iperf_server 
                            is repeated, this parameter may also be repeated 
                            (in the same order), or designated only once (same 
                            for all --iperf_server). If not set --meta_region 
                            will be assumed

--iperf_server_service      The name of the compute service corresponding to 
                            --iperf_server. If --iperf_server is repeated, this 
                            parameter may also be repeated (in the same order), 
                            or designated only once (same for all 
                            --iperf_server). If not set --meta_compute_service 
                            will be assumed

--iperf_server_service_id   The id of the compute service corresponding to 
                            --iperf_server. If --iperf_server is repeated, this 
                            parameter may also be repeated (in the same order), 
                            or designated only once (same for all 
                            --iperf_server). If not set 
                            --meta_compute_service_id will be assumed
                            
--iperf_time                The time in seconds to transmit for. Iperf normally 
                            works by repeatedly sending an array of --iperf_len 
                            bytes for time seconds. Default is 10 seconds. See 
                            also the --iperf_len and --iperf_num options
                            
--iperf_tos                 The type-of-service for outgoing packets. (Many 
                            routers ignore the TOS field.) You may specify the 
                            value in hex with a 0x prefix, in octal with a 
                            0 prefix, or in decimal. For example, 0x10 
                            hex = 020 octal = 16 decimal. The TOS numbers 
                            specified in RFC 1349 are: 
                              IPTOS_LOWDELAY     minimize delay        0x10
                              IPTOS_THROUGHPUT   maximize throughput   0x08
                              IPTOS_RELIABILITY  maximize reliability  0x04
                              IPTOS_LOWCOST      minimize cost         0x02
                              
--iperf_tradeoff            Run Iperf in tradeoff testing mode. This will cause 
                            the server to connect back to the client to conduct
                            testing in the opposite direction (downlink). When 
                            used 2 records will exist for each --iperf_server,
                            one with bandwidth_direction=up (client => server) 
                            and the next bandwidth_direction=down (server => 
                            client)
                              
--iperf_ttl                 The time-to-live for outgoing multicast packets. 
                            This is essentially the number of router hops to go 
                            through, and is also used for scoping. Default is 
                            1, link-local

--iperf_udp                 If set, testing will use UDP instead of TCP

--iperf_warmup              Number of initial seconds of testing to ignore for
                            result calculations. Default is 0

--iperf_window              Sets the socket buffer sizes to the specified 
                            value. For TCP, this sets the TCP window size. For 
                            UDP it is just the buffer which datagrams are 
                            received in, and so limits the largest receivable 
                            datagram size
                            
--iperf_zerocopy            Use a "zero copy" method of sending data, such as 
                            sendfile, instead of the usual write. Requires 
                            Iperf 3

--meta_compute_service      The name of the compute service the testing is 
                            performed on. May also be specified using the 
                            environment variable bm_compute_service
                            
--meta_compute_service_id   The id of the compute service the testing is 
                            performed on. Added to saved results. May also be 
                            specified using the environment variable 
                            bm_compute_service_id
                            
--meta_cpu                  CPU descriptor - if not specified, it will be set 
                            using the model name attribute in /proc/cpuinfo
                            
--meta_instance_id          The compute service instance type the testing is
                            performed on (e.g. c3.xlarge). May also be 
                            specified using the environment variable 
                            bm_instance_id
                            
--meta_memory               Memory descriptor - if not specified, the system
                            memory size will be used
                            
--meta_os                   Operating system descriptor - if not specified, 
                            it will be taken from the first line of /etc/issue
                            
--meta_provider             The name of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider
                            
--meta_provider_id          The id of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider_id
                            
--meta_region               The service region this test pertains to. May also 
                            be specified using the environment variable 
                            bm_region
                            
--meta_resource_id          An optional benchmark resource identifiers. May 
                            also be specified using the environment variable 
                            bm_resource_id
                            
--meta_run_id               An optional benchmark run identifier. May also be 
                            specified using the environment variable bm_run_id
                            
--meta_run_group_id         An optional benchmark group run identifier. May 
                            also be specified using the environment variable 
                            bm_run_group_id
                            
--meta_test_id              Identifier for the test. May also be specified 
                            using the environment variable bm_test_id

--nopdfreport               Do not generate PDF version of test report - 
                            report.pdf. (wkhtmltopdf dependency removed if 
                            specified)

--noreport                  Do not generate html or PDF test reports - 
                            report.zip and report.pdf (gnuplot, wkhtmltopdf and
                            zip dependencies removed if specified)
                            
--output                    The output directory to use for writing test data 
                            (logs and artifacts). If not specified, the current 
                            working directory will be used

--verbose                   Show verbose output
                            
                            
DEPENDENCIES
This benchmark has the following dependencies:

gnuplot     Generates report graphs (required unless --noreport set)

php         Test automation scripts (/usr/bin/php)

wkhtmltopdf Generates PDF version of report - download from 
            http://wkhtmltopdf.org (required unless --nopdfreport set)

zip         Used to compress test artifacts (collectd-rrd.zip and report.zip)


USAGE
# run 1 test iteration against server 10.0.1.1
./run.sh --iperf_server 10.0.1.1

# run 1 test iteration against server 10.0.1.1 on port 80
./run.sh --iperf_server 10.0.1.1:80

# run 1 test iteration with some metadata
./run.sh --iperf_server 10.0.1.1 --meta_compute_service_id aws:ec2 --meta_instance_id c3.xlarge --meta_region us-east-1 --meta_test_id aws-0415


EXIT CODES:
  0 test successful
  1 test failed

EOF
  exit
elif [ -f "/usr/bin/php" ]; then
  $( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/lib/run.php $@
  exit $?
else
  echo "Error: missing dependency php-cli (/usr/bin/php)"
  exit 1
fi
