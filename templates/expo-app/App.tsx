import { StatusBar } from "expo-status-bar";
import { StyleSheet, Text, View } from "react-native";

/**
 * Golden-template entry point. The Coding Agent replaces this with the
 * app's real navigation and screens per SPEC.md / SCREENS.md.
 */
export default function App() {
  return (
    <View style={styles.container}>
      <Text style={styles.title}>Factory App</Text>
      <Text>Replace me per SPEC.md</Text>
      <StatusBar style="auto" />
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, alignItems: "center", justifyContent: "center", gap: 8 },
  title: { fontSize: 24, fontWeight: "600" },
});
