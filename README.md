logstreamer
===========

Stream any log on the network with compression on-the-fly, resume feature, etc.

Main usage is Apache log files but it can be used for anything else.

Server is able to aggregate data from different sources, with atomic commits.
Client can write data on local memory / storage, if distant resource not available. 