// assets/bootstrap.js
import { startStimulusApp } from '@symfony/stimulus-bridge';

const app = startStimulusApp(require.context('./controllers', true, /\.js$/));

// You can manually register controllers like this:
// app.register('some_controller_name', SomeImportedController);
