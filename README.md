logstreamer
===========

Stream any log on the network with compression on-the-fly, resume feature, etc.

Main usage is Apache log files but it can be used for anything else.

Server is able to aggregate data from different sources, with atomic commits.

Client can write data on local memory / storage, if distant resource not available. 
Client also handle compressing data on-the-fly : memory usage is lowered, and bandwidth too.


logstreamerHTTP
===============
This is a fork of logstreamer, but instead of sending content to a TCP socket, it sends content 
as chunks to an HTTP URL as POST data. It can send compressed data.

Versions
--------
Tagged version 1.0 is working as TCP streams. 
Compression is not fully working. Do not use it at the moment, before testing code...

