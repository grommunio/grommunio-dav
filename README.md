# grommunio Dav

[![project license](https://img.shields.io/github/license/grommunio/grommunio-dav.svg)](LICENSE)
[![latest version](https://shields.io/github/v/tag/grommunio/grommunio-dav)](https://github.com/grommunio/grommunio-dav/tags)
[![scrutinizer](https://img.shields.io/scrutinizer/build/g/grommunio/grommunio-dav)](https://scrutinizer-ci.com/g/grommunio/grommunio-dav/)
[![code size](https://img.shields.io/github/languages/code-size/grommunio/grommunio-dav)](https://github.com/grommunio/grommunio-dav)

[![pull requests welcome](https://img.shields.io/badge/PRs-welcome-ff69b4.svg)](https://github.com/grommunio/grommunio-dav/issues?q=is%3Aissue+is%3Aopen+label%3A%22help+wanted%22)
[![code with love by grommunio](https://img.shields.io/badge/%3C%2F%3E%20with%20%E2%99%A5%20by-grommunio-ff1414.svg)](https://grommunio.com)
[![twitter](https://img.shields.io/twitter/follow/grommunio?style=social)](https://twitter.com/grommunio)

**grommunio Dav is an open-source application to provide CalDAV and CardDAV to compatible applications and devices such as macOS Calendar, macOS Contacts, Thunderbird/Lightning and others.**

<details open="open">
<summary>Overview</summary>

- [About](#about)
  - [Built with](#built-with)
- [Getting Started](#getting-started)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
- [Usage](#usage)
- [Status](#status)
- [Support](#support)
- [Project assistance](#project-assistance)
- [Contributing](#contributing)
- [Security](#security)
- [Coding style](#coding-style)
- [License](#license)

</details>

---

## About grommunio Dav

- Provides standardized CalDAV and CardDAV interfaces to groupware data (contacts, calendar and tasks)
- **Multi-platform** supports various CalDAV and CardDAV clients, such as macOS Calendar, macOS Contacts, Thunderbird/Lightning, Evolution and many other CalDAV/CardDAV clients as well as other applications used, such as [Dash](https://get-dash.com)
- **Compatible**, works with various web servers such as nginx, apache and others; usage of nginx is recommended
- **Highly efficient**, averaging at 4MB per connection, per device of memory usage (using nginx with php-fpm)
- **Distributable**, compatible with load balancers such as haproxy, apisix, KEMP and others
- **Scalable**, enabling multi-server and multi-location deployments
- **High-performance**, allowing nearly wire speeds for store synchronization
- **Secure**, with certifications through independent security research and validation

### Built with

- PHP **7.x** (PHP **8.0** and **8.1** in finalization)
- PHP modules: ctype, curl, dom, iconv, mbsting, sqlite, xml, xmlreader, xmlwriter
- PHP backend module: mapi

## Getting Started

### Prerequisites

- A working **web server**, with a working **TLS** configuration (nginx recommended)
- **PHP**, preferably available as fpm pool
- **Zcore** MAPI transport (provided by [gromox](https://github.com/grommunio/gromox))

### Installation

- Deploy grommunio-dav at a location of your choice, such as **/usr/share/grommunio-dav**
- Adapt version.php with the adequate version string, see **[/build/version.php.in](/build/version.php.in)**
- Provide a default configuration file as config.php, see **[/config.php](/config.php)**
- Adapt web server configuration according to your needs, **[/build](/build)** provides some examples
- Prepare PHP configuration according to your needs, **[/build](/build)** provides some examples
- (Optional) Setting up DNS SRV records for simplified account configuration is recommended:

```
_carddavs._tcp 86400 IN SRV 10 20 443 my.example.com.
_caldavs._tcp 86400 IN SRV 10 20 443 my.example.com.
_caldavs._tcp TXT path=/dav
_carddavs._tcp TXT path=/dav
```

## Usage

- You can use your webbrowser to point to ```https://my.example.com/dav/``` or alternatively directly to your calendar URL ```https://my.example.com/dav/calendars/<user>/Calendar/```
- Enter your account **credentials** (username and password)

## Status

- [Top Feature Requests](https://github.com/grommunio/grommunio-dav/issues?q=label%3Aenhancement+is%3Aopen+sort%3Areactions-%2B1-desc) (Add your votes using the 👍 reaction)
- [Top Bugs](https://github.com/grommunio/grommunio-dav/issues?q=is%3Aissue+is%3Aopen+label%3Abug+sort%3Areactions-%2B1-desc) (Add your votes using the 👍 reaction)
- [Newest Bugs](https://github.com/grommunio/grommunio-dav/issues?q=is%3Aopen+is%3Aissue+label%3Abug)

## Support

- Support is available through **[grommunio GmbH](https://grommunio.com)** and its partners.
- grommunio Dav community is available here: **[grommunio Community](https://community.grommunio.com)**

For direct contact to the maintainers (for example to supply information about a security-related responsible disclosure), you can contact grommunio directly at [dev@grommunio.com](mailto:dev@grommunio.com)

## Project assistance

If you want to say **thank you** or/and support active development of grommunio Dav:

- Add a [GitHub Star](https://github.com/grommunio/grommunio-dav) to the project.
- Tweet about grommunio Dav.
- Write interesting articles about the project on [Dev.to](https://dev.to/), [Medium](https://medium.com/), your personal blog or any medium you feel comfortable with.

Together, we can make grommunio Dav **better**!

## Contributing

First off, thanks for taking the time to contribute! Contributions are what make the open-source community such an amazing place to learn, inspire, and create. Any contributions you make will benefit everybody else and are **greatly appreciated**.

Please read [our contribution guidelines](docs/CONTRIBUTING.md), and **thank you** for being involved!

## Security

grommunio Dav follows good practices of security. grommunio constantly monitors security-related issues.
grommunio Dav is provided **"as is"** without any **warranty**. For professional support options through subscriptions, head over to [grommunio](https://grommunio.com).

_For more information and to report security issues, please refer to our [security documentation](docs/SECURITY.md)._

## Coding style

This repository follows a customized coding style. The coding style can be validated anytime by the repositories provided [configuration file](.phpcs). To understand how to run the coding style toolchain, follow the instructions in [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md)

## License

This project is licensed under the **GNU Affero General Public License v3**.

See [LICENSE](LICENSE) for more information.
