module.exports = {
  content: [
    './assets/**/*.{js,ts}',
    './templates/**/*.html.twig',
    './node_modules/flowbite/**/*.js',
  ],
  theme: {
    extend: {},
  },
  plugins: [
    require('flowbite/plugin')
  ],
};
