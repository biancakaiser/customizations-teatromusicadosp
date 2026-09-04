let path = require("path");
let webpack = require("webpack");
const { VueLoaderPlugin } = require("vue-loader");

// `webpack serve` (npm run dev) sets env.WEBPACK_SERVE = true automatically;
// `webpack` (npm run build) does not, so publicPath falls back to a relative path.
module.exports = (env) => {
  const isDevServer = Boolean(env && env.WEBPACK_SERVE);

  return {
    entry: {
      components: "./components.js",
      "espetaculos-form-hook": "./src/espetaculos-form-hook/espetaculos-form-hook.js",
    },
    mode: "development",
    devtool: "eval-source-map",
    output: {
      path: path.resolve(__dirname, "build/"),
      filename: "[name].bundle.js",
      publicPath: isDevServer ? "http://127.0.0.1:8080/" : "",
    },
    devServer: {
      static: {
        directory: path.resolve(__dirname, ""),
      },
      hot: true,
      host: "127.0.0.1",
      port: 8080,
      headers: {
        "Access-Control-Allow-Origin": "*",
      },
      allowedHosts: "all",
      client: {
        overlay: true,
      },
      liveReload: false,
    },
    module: {
      rules: [
        {
          test: /\.vue$/,
          loader: "vue-loader"
        },
        {
          test: /\.js$/,
          loader: "babel-loader",
        },
        {
          test: /\.css$/,
          use: ["vue-style-loader", "css-loader"],
        }
      ],
    },
    plugins: [new VueLoaderPlugin()]
  };
};
