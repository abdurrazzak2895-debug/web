FROM debian:12-slim

ENV DEBIAN_FRONTEND=noninteractive

# SSH remains on 22 for Railway TCP Proxy; ttyd uses 7681 for the HTTP domain.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       openssh-server \
       ttyd \
       bash \
       ca-certificates \
       curl \
       git \
       sudo \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /run/sshd /root/.ssh \
    && chmod 700 /root/.ssh \
    && sed -i 's/^#\?Port .*/Port 22/' /etc/ssh/sshd_config \
    && sed -i 's/^#\?PasswordAuthentication .*/PasswordAuthentication yes/' /etc/ssh/sshd_config \
    && sed -i 's/^#\?PermitRootLogin .*/PermitRootLogin yes/' /etc/ssh/sshd_config \
    && printf '%s\n' \
       'UsePAM no' \
       'X11Forwarding no' \
       'AllowTcpForwarding no' \
       'ClientAliveInterval 60' \
       'ClientAliveCountMax 3' \
       >> /etc/ssh/sshd_config

COPY railway/start-ssh-ttyd.sh /usr/local/bin/start-ssh-ttyd.sh
RUN chmod 755 /usr/local/bin/start-ssh-ttyd.sh

# Railway HTTP public domain must target this port. TCP Proxy must target 22.
EXPOSE 7681
EXPOSE 22

ENTRYPOINT ["/usr/local/bin/start-ssh-ttyd.sh"]
