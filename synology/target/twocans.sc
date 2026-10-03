[twocans_phones]
title="twocans: phones (SIP)"
desc="twocans"
port_forward="no"
dst.ports="5060/tcp,udp"

[twocans_line]
title="twocans: phone line (SIP)"
desc="twocans"
port_forward="yes"
dst.ports="5062/udp"

[twocans_audio]
title="twocans: call audio (RTP)"
desc="twocans"
port_forward="yes"
dst.ports="10000:10100/udp"

[twocans_web]
title="twocans: web app"
desc="twocans"
port_forward="no"
dst.ports="8083/tcp"

[twocans_https]
title="twocans: HTTPS"
desc="twocans"
port_forward="yes"
dst.ports="8443/tcp"
