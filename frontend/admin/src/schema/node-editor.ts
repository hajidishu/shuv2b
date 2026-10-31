import {
    nodeFields,
    options,
    naiveTlsNested,
    mieruNested,
    mieruDefaults,
} from "./nodes";
import type { Field, Row } from "../types";

// Field order, columns and visibility follow the original per-protocol drawers.
export function nodeEditorFields(type: string, values: Row): Field[] {
    const isNaive =
        type === "naive" || (type === "v2node" && values.protocol === "naive");
    const isMieru =
        type === "mieru" || (type === "v2node" && values.protocol === "mieru");
    const source = Object.fromEntries(nodeFields(type).map((f) => [f.key, f]));
    const field = (
        key: string,
        span = 12,
        extra: Partial<Field> = {},
    ): Field => ({ ...source[key], key, span, help: undefined, ...extra });
    const cfg = (key: string, label: string): Field => ({
        ...field(key),
        label,
        drawerWidth: "80%",
        configStyle: true,
    });
    const yesNo = (key: string, span = 6) =>
        field(key, span, {
            kind: "select",
            options: [
                { value: 0, label: "否" },
                { value: 1, label: "是" },
            ],
        });
    const tls = (key: string) => {
        const configuration = cfg(key, "安全性配置"),
            mode = Number(values.tls ?? 0);
        if (isNaive) {
            configuration.nested = naiveTlsNested!.map((item) => ({
                ...item,
                ...(["provider", "dns_env"].includes(item.key)
                    ? { when: (v: Row) => v.cert_mode === "dns" }
                    : {}),
                ...(["cert_file", "key_file"].includes(item.key)
                    ? {
                          when: (v: Row) => v.cert_mode === "file",
                          required: true,
                      }
                    : {}),
            }));
            configuration.jsonDefaults = { server_name: "", cert_mode: "http" };
            return field("tls", type === "v2node" ? 6 : 12, {
                label: "安全性",
                default: 1,
                options: [{ label: "TLS", value: 1 }],
                config: configuration,
            });
        }
        if (type === "vmess")
            configuration.nested = [
                { key: "serverName", label: "Server Name" },
                {
                    key: "allowInsecure",
                    label: "Allow Insecure",
                    kind: "switch",
                },
            ];
        else
            configuration.nested = configuration.nested
                ?.filter((n) =>
                    mode === 2
                        ? [
                              "server_name",
                              "fingerprint",
                              "public_key",
                              "private_key",
                              "short_id",
                              "server_port",
                              "dest",
                              "xver",
                              "allow_insecure",
                              "ech",
                              "ech_server_name",
                              "ech_config",
                              "ech_key",
                          ].includes(n.key)
                        : [
                              "server_name",
                              "allow_insecure",
                              "fingerprint",
                              "cert_mode",
                              "cert_file",
                              "key_file",
                              "pinned_peer_cert_sha256",
                              "provider",
                              "dns_env",
                              "reject_unknown_sni",
                              "ech",
                              "ech_server_name",
                              "ech_config",
                              "ech_key",
                          ].includes(n.key),
                )
                .map((n) => ({
                    ...n,
                    ...(n.key === "ech"
                        ? {
                              options: [
                                  { value: "", label: "无" },
                                  { value: "cloudflare", label: "Cloudflare" },
                                  { value: "custom", label: "自定义 SNI" },
                              ],
                          }
                        : {}),
                    ...(n.key === "cert_mode"
                        ? {
                              label: "证书模式Cert Mode",
                              options: [
                                  { value: "self", label: "自签名" },
                                  {
                                      value: "remote",
                                      label: "自签名(面板下发)",
                                  },
                                  { value: "http", label: "HTTP申请" },
                                  { value: "dns", label: "DNS申请" },
                                  { value: "none", label: "无证书(关闭TLS)" },
                              ],
                          }
                        : {}),
                    ...(["cert_file", "key_file"].includes(n.key)
                        ? { when: (v: Row) => v.cert_mode !== "none" }
                        : {}),
                    ...(n.key === "pinned_peer_cert_sha256"
                        ? { when: (v: Row) => v.cert_mode === "remote" }
                        : {}),
                    ...(["provider", "dns_env"].includes(n.key)
                        ? { when: (v: Row) => v.cert_mode === "dns" }
                        : {}),
                    ...(["ech_server_name", "ech_config", "ech_key"].includes(
                        n.key,
                    )
                        ? { when: (v: Row) => v.ech === "custom" }
                        : {}),
                }));
        configuration.jsonDefaults =
            type === "vmess"
                ? { serverName: "", allowInsecure: 0 }
                : mode === 1
                  ? {
                        server_name: "",
                        cert_mode: "self",
                        provider: "",
                        dns_env: "",
                        reject_unknown_sni: 0,
                        allow_insecure: 0,
                    }
                  : { server_name: "", allow_insecure: 0 };
        return field("tls", type === "v2node" ? 6 : 12, {
            label: "安全性",
            config: mode ? configuration : undefined,
        });
    };
    const network = (configKey: string) =>
        field("network", 12, {
            config: { ...cfg(configKey, "协议配置"), nested: undefined },
        });
    const top = [
        field("name", 8, { placeholder: "请输入节点名称" }),
        field("rate", 4, {
            label: "倍率",
            ariaLabel: "流量倍率",
            addonAfter: "x",
            placeholder: "请输入节点倍率",
        }),
        field("tags", 12, {
            label: "节点标签",
            placeholder: "输入后回车添加标签",
        }),
        field("group_id", 12, { placeholder: "请选择权限组" }),
    ];
    const addresses = ["v2node", "naive", "mieru"].includes(type)
        ? [
              field("host", 6, { placeholder: "地址或IP" }),
              field("listen_ip", 6, {
                  placeholder: "地址或IP默认为0.0.0.0",
              }),
          ]
        : [
              field("host", 12, {
                  label: "节点地址",
                  ariaLabel: "连接地址",
                  placeholder: "地址或IP",
              }),
          ];
    const insecure = ["hysteria", "tuic", "anytls"].includes(type);
    const ports = [
        field("port", insecure ? 4 : 6, { placeholder: "用户连接端口" }),
        field("server_port", insecure ? 4 : 6, {
            ...(isMieru ? { kind: "text" as const } : {}),
            label: "服务端口",
            placeholder: "服务端开放端口",
        }),
        ...(insecure ? [yesNo("insecure", 4)] : []),
    ];
    const result = [
        ...top,
        ...(type === "hysteria"
            ? [
                  field("version", 3, {
                      label: "HYSTERIA版本",
                      options: [
                          { value: 1, label: "v1" },
                          { value: 2, label: "v2" },
                      ],
                  }),
              ]
            : []),
        ...addresses,
        ...ports,
    ];
    const protocol = type === "v2node" ? values.protocol : type;
    if (type === "v2node") {
        result.push(
            field("protocol", 6, {
                options: [
                    "anytls",
                    "naive",
                    "mieru",
                    "hysteria2",
                    "shadowsocks",
                    "trojan",
                    "tuic",
                    "vless",
                    "vmess",
                ].map((value) => ({
                    value,
                    label: (
                        {
                            anytls: "AnyTLS",
                            naive: "Naive",
                            mieru: "Mieru",
                            hysteria2: "Hysteria2",
                            shadowsocks: "Shadowsocks",
                            trojan: "Trojan",
                            tuic: "Tuic",
                            vless: "VLess",
                            vmess: "VMess",
                        } as Row
                    )[value],
                })),
            }),
        );
        if (protocol && !["shadowsocks", "mieru"].includes(protocol)) {
            const f = tls("tls_settings");
            f.options = (
                protocol === "vmess"
                    ? [0, 1]
                    : protocol === "vless"
                      ? [0, 1, 2]
                      : protocol === "anytls"
                        ? [1, 2]
                        : [1]
            ).map((value) => ({
                value,
                label: ["无", "TLS", "Reality"][value],
            }));
            result.push(f);
        }
    } else if (["vmess", "vless", "naive"].includes(type))
        result.push(tls(type === "vmess" ? "tlsSettings" : "tls_settings"));
    if (
        ["vmess", "vless", "trojan"].includes(type) ||
        (type === "v2node" &&
            protocol &&
            !["tuic", "hysteria2", "naive", "mieru"].includes(protocol))
    ) {
        const f = network(
            type === "vmess" ? "networkSettings" : "network_settings",
        );
        if (type === "v2node" || type === "trojan")
            f.options = (
                protocol === "shadowsocks"
                    ? ["tcp", "http"]
                    : protocol === "trojan"
                      ? ["tcp", "ws", "grpc"]
                      : ["tcp", "ws", "grpc", "httpupgrade", "xhttp"]
            ).map((value) => ({
                value,
                label: (
                    {
                        tcp: "TCP",
                        ws: "WebSocket",
                        grpc: "gRPC",
                        http: "HTTP伪装",
                        httpupgrade: "HTTPUpgrade",
                        xhttp: "XHTTP",
                    } as Row
                )[value],
            }));
        result.push(f);
        if (
            type === "v2node" &&
            ["ws", "grpc", "xhttp"].includes(values.network)
        )
            result.push(
                field("trusted_x_forwarded_for", 12, {
                    label: "信任的XFF头部(获取真实IP)",
                    placeholder:
                        "常见头部:X-Forwarded-For CF-Connecting-IP X-Real-IP",
                }),
            );
    }
    if (isMieru) {
        for (const port of ports) {
            port.placeholder = "端口或范围，例如 2012-2022";
            port.help = "支持单端口或连续端口范围";
        }
        result.push(
            field("network", 12, {
                label: "传输协议",
                required: true,
                default: "tcp",
                options: [
                    { value: "tcp", label: "TCP" },
                    { value: "udp", label: "UDP" },
                ],
                config: {
                    ...cfg("network_settings", "Mieru 配置"),
                    nested: mieruNested,
                    jsonDefaults: mieruDefaults,
                    help: "MTU 范围为 1280–1500，默认 1400。Mihomo 不支持自定义 MTU；自定义 MTU 的 UDP 节点只下发到原生 Mieru 分享链接。Traffic Pattern 从 mieru export traffic-pattern 获取。",
                },
            }),
        );
    }
    if (isNaive) {
        result.push(
            field("network", 12, {
                label: "监听网络",
                required: false,
                options: [
                    { value: "tcp", label: "TCP（HTTP/2）" },
                    { value: "udp", label: "UDP（QUIC）" },
                    { value: "", label: "TCP + UDP" },
                ],
                help: "仅支持 Naive 原生监听，不支持 WebSocket、gRPC 或 Reality",
            }),
        );
        if (values.network !== "tcp")
            result.push(
                field("congestion_control", 12, {
                    label: "QUIC 拥塞控制（服务端）",
                    default: "bbr",
                    options: options("bbr", "cubic", "reno"),
                }),
            );
    }
    if (["tuic", "hysteria", "anytls", "trojan"].includes(type))
        result.push(
            field("server_name", 12, {
                label: "服务器名称指示(sni)",
                placeholder: "当节点地址与证书不一致时用于证书验证",
            }),
        );
    if (type === "trojan") result.push(yesNo("allow_insecure", 12));
    if (protocol === "shadowsocks") {
        result.push(field("cipher", 12, { label: "加密算法" }));
        if (type !== "v2node") {
            result.push(
                field("obfs", 12, {
                    options: [
                        { label: "无", value: "" },
                        { label: "HTTP", value: "http" },
                    ],
                }),
            );
            if (values.obfs)
                result.push({
                    ...cfg("obfs_settings", "混淆配置"),
                    inlineObfs: true,
                });
        }
    }
    if (protocol === "tuic")
        result.push(
            yesNo("disable_sni"),
            field("udp_relay_mode", 6, { label: "UDP转发模式" }),
            field("congestion_control", 6, { label: "拥塞控制算法" }),
            yesNo("zero_rtt_handshake"),
        );
    if (protocol === "hysteria2" || type === "hysteria") {
        result.push(
            field("obfs", 6, {
                label: "混淆方式obfs",
                kind: "select",
                options: [
                    { label: "无", value: "" },
                    {
                        label:
                            type === "hysteria" && Number(values.version) === 1
                                ? "xplus"
                                : "salamander",
                        value:
                            type === "hysteria" && Number(values.version) === 1
                                ? "xplus"
                                : "salamander",
                    },
                ],
            }),
        );
        if (values.obfs)
            result.push(
                field("obfs_password", 6, {
                    label: "混淆密码obfs_password",
                    placeholder: "留空自动生成",
                }),
            );
        result.push(
            field("up_mbps", 12, {
                label: "上行带宽",
                addonAfter: "Mbps",
                placeholder: "服务端发送带宽,留空或填0使用BBR",
            }),
            field("down_mbps", 12, {
                label: "下行带宽",
                addonAfter: "Mbps",
                placeholder: "服务端接收带宽,留空或填0使用BBR",
            }),
        );
    }
    if (protocol === "anytls") result.push(cfg("padding_scheme", "填充方案"));
    if (protocol === "vless")
        result.push(
            field("encryption", 12, {
                label: "加密方式",
                kind: "select",
                options: [
                    { label: "无", value: "" },
                    {
                        label: "MLKEM768X25519PLUS",
                        value: "mlkem768x25519plus",
                    },
                ],
                config: values.encryption
                    ? {
                          ...cfg("encryption_settings", "加密配置"),
                          jsonDefaults: {
                              mode: "native",
                              rtt: "0rtt",
                              ticket: "600s",
                          },
                          nested: [
                              {
                                  key: "mode",
                                  label: "Mode",
                                  kind: "select",
                                  options: options(
                                      "native",
                                      "xorpub",
                                      "random",
                                  ),
                              },
                              {
                                  key: "rtt",
                                  label: "RTT",
                                  kind: "select",
                                  options: options("0rtt", "1rtt"),
                              },
                              {
                                  key: "ticket",
                                  label: "Ticket time",
                                  when: (v) => v.rtt === "0rtt",
                              },
                              {
                                  key: "server_padding",
                                  label: "Server Padding",
                              },
                              {
                                  key: "private_key",
                                  label: "Private Key",
                                  kind: "password",
                              },
                              {
                                  key: "client_padding",
                                  label: "Client Padding",
                              },
                              {
                                  key: "password",
                                  label: "Password",
                                  kind: "password",
                              },
                          ],
                      }
                    : undefined,
            }),
            field("flow", 12, { label: "XTLS流控算法" }),
        );
    if (type === "vmess")
        result.push(
            cfg("ruleSettings", "规则配置"),
            cfg("dnsSettings", "DNS 配置"),
        );
    result.push(
        field("parent_id", 12, {
            placeholder: "无",
            helpLink: {
                label: "更多解答",
                href: "https://docs.v2board.com/use/node.html#父节点与子节点关系",
            },
        }),
        field("route_id", 12, { label: "路由组", placeholder: "请选择路由组" }),
    );
    return result;
}

export function nodeSubmissionFields(fields: Field[]): Field[] {
    return fields.flatMap((f) => (f.config ? [f, f.config] : [f]));
}
