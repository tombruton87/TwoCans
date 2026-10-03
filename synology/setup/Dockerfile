# The Synology package's setup container: Docker's own CLI image, with what
# ./install.sh needs besides. See run.sh.
FROM docker:27-cli
RUN apk add --no-cache bash curl iproute2 coreutils gawk tar procps tzdata
