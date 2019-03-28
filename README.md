# lupus Frontproxy

Proxies main application requests and prepares pre-rendered pages by combining the pre-rendered page-shell provided
by the frontend and the content provided by the backend. Sub-sequent requests will hit fetch the content from the
backend via API requests directly.

The package can be used with pluggable request mergers, while the package ships with a working example for a
Nuxt.js based generated static frontend.

## Example setup

Add a web docroot for your front-proxy  as shown in the provided example, in the `example` directory.

The example is working well with https://github.com/drunomics/multisite-request-matcher/ and
https://github.com/drunomics/phapp-cli since it uses the environment variable names as defined by those packages.
