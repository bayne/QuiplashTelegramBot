FROM golang
RUN apt install ca-certificates git
RUN go get github.com/ianlewis/cloud-dyndns-client/cmd/cloud-dyndns-client
RUN go install -v github.com/ianlewis/cloud-dyndns-client/cmd/cloud-dyndns-client

COPY config.json config.json
EXPOSE 8080
CMD ["cloud-dyndns-client", "-config", "config.json"]
